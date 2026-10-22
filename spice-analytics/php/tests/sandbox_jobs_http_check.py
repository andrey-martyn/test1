"""Real PHP 7.3 HTTP routes enqueue sandbox work without opening MySQL.

The fixture contains synthetic credentials and no MySQL server. Actual shop
price operations belong to the PHP 8.2 CLI worker and its separate checks.
"""
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time

import httpx


ROOT = Path(__file__).resolve().parents[2]
IMAGE = os.environ.get("ANALYTICS_PHP_IMAGE", "lavka-php73-dev")
NAME = "lavka-sandbox-jobs-http-" + secrets.token_hex(5)
PRIVATE = "/fixture/analytics-private-test"
SECRET = "SYNTHETIC_SANDBOX_DATABASE_PASSWORD_" + secrets.token_hex(8)


def store_config(admin=False):
    base = "https://test.lavka-speciy.com/" + ("admin/" if admin else "")
    constants = {
        "DB_DATABASE": "lavkasp_test", "DB_PREFIX": "oc_", "DB_HOSTNAME": "127.0.0.1",
        "DB_PORT": "3306", "DB_PASSWORD": SECRET, "HTTP_SERVER": base, "HTTPS_SERVER": base,
        "DIR_STORAGE": "/fixture/test/storage/",
        "DIR_APPLICATION": "/fixture/test/" + ("admin/" if admin else "catalog/"),
        "DIR_SYSTEM": "/fixture/test/system/", "DIR_IMAGE": "/fixture/test/image/",
    }
    if admin:
        constants.update(HTTP_CATALOG="https://test.lavka-speciy.com/",
                         HTTPS_CATALOG="https://test.lavka-speciy.com/")
    return "<?php\n" + "".join(f"define('{name}', '{value}');\n" for name, value in constants.items())


def digest(paths):
    return {str(path): hashlib.sha256(path.read_bytes()).hexdigest() for path in paths}


with tempfile.TemporaryDirectory() as temporary:
    fixture = Path(temporary)
    data = fixture / "analytics-private-test"
    old = fixture / "analytics-private"
    shop = fixture / "test"
    for path in [data, old, shop, shop / "admin", shop / "storage", shop / "catalog",
                 shop / "system", shop / "image"]:
        path.mkdir(mode=0o700 if path in [data, old] else 0o755, parents=True, exist_ok=True)
    (shop / "config.php").write_text(store_config())
    (shop / "admin/config.php").write_text(store_config(True))
    (old / "source.json").write_text(json.dumps({"database": "lavkasp_lavka", "password": SECRET}))
    source = {"host": "127.0.0.1", "port": 3306, "unix_socket": "", "database": "lavkasp_test",
              "prefix": "oc_", "user": "lavkasp_sandbox", "password": SECRET, "ssl_ca": ""}
    marker = {"environment": "sandbox", "database": "lavkasp_test", "allowed_shop_database": "lavkasp_test",
              "source_user": "lavkasp_sandbox", "data_dir": PRIVATE,
              "previous_data_dir": "/fixture/analytics-private", "shop_root": "/fixture/test",
              "public_url": "https://test.lavka-speciy.com/analytics", "writes_enabled": True,
              "test_store_verified": True, "admin_config": "/fixture/test/admin/config.php"}
    for name, value in [("source.json", source), ("sandbox.json", marker)]:
        path = data / name
        path.write_text(json.dumps(value))
        path.chmod(0o600)
    protected = [old / "source.json", data / "source.json", data / "sandbox.json",
                 shop / "config.php", shop / "admin/config.php"]
    before = digest(protected)
    password = secrets.token_urlsafe(24)
    docker = ["docker", "run", "--rm", "--user", f"{os.getuid()}:{os.getgid()}",
              "-v", str(ROOT) + ":/app:ro", "-v", temporary + ":/fixture",
              "-e", "ANALYTICS_DATA_DIR=" + PRIVATE, "-e", "ANALYTICS_BASE_PATH=/analytics"]
    seed = r'''
require "php/src/Auth.php"; require "php/src/Competitors.php";
\Lavka\Auth::setPassword(getenv("ANALYTICS_DATA_DIR"), stream_get_contents(STDIN));
$db=\Lavka\competitors_db(getenv("ANALYTICS_DATA_DIR"));
$db->exec("UPDATE competitor_targets SET enabled=0");
$target=\Lavka\competitors_save_target($db,["source_id"=>"lavka","kind"=>"product",
 "url"=>"https://test.lavka-speciy.com/index.php?route=product/product&product_id=999501",
 "label"=>"Синтетический товар песочницы","enabled"=>true,"match_group"=>"sandbox-http",
 "match_confirmed"=>true,"pack_label"=>"50 г"],null,true);
echo json_encode(["target_id"=>$target["id"]]);
'''
    result = subprocess.run(docker + ["-i", IMAGE, "php", "-r", seed], input=password.encode(),
                            capture_output=True, check=True)
    target_id = json.loads(result.stdout)["target_id"]
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    server = subprocess.Popen(docker + ["--name", NAME, "-p", f"127.0.0.1:{port}:8000", IMAGE,
                                       "php", "-S", "0.0.0.0:8000", "-t", "php/public", "php/public/index.php"],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    origin = f"http://127.0.0.1:{port}"
    endpoint = origin + "/analytics/index.php?route="
    responses = []
    try:
        with httpx.Client(timeout=10, trust_env=False) as client:
            for _ in range(600):
                try:
                    if client.get(endpoint + "/health").status_code == 200:
                        break
                except httpx.TransportError:
                    pass
                time.sleep(.1)
            else:
                raise RuntimeError("Synthetic sandbox HTTP server did not start")
            sandbox = "/api/competitors/sandbox/"
            for path in ["status", "jobs/" + "a" * 32, "plan", "apply", "rollback"]:
                response = client.get(endpoint + sandbox + path)
                assert response.status_code == 401, (path, response.status_code)
            assert client.post(endpoint + sandbox + "plan", json={"selections": []}).status_code == 401
            response = client.post(endpoint + "/api/login", json={"password": password}, headers={"Origin": origin})
            assert response.status_code == 200, response.text
            assert "httponly" in response.headers["set-cookie"].lower()
            status = client.get(endpoint + sandbox + "status")
            responses.append(status)
            assert status.status_code == 200 and status.json()["enabled"] and status.json()["can_write"]
            assert status.json()["recent_jobs"] == []
            assert not (data / "sandbox-price-jobs.sqlite3").exists(), "Polling must not create a queue"
            plan = {"selections": [{"target_id": target_id, "base_50": "88.20"}]}
            response = client.post(endpoint + sandbox + "plan", json=plan, headers={"Origin": "https://attacker.invalid"})
            assert response.status_code == 403
            for action in ["plan", "apply", "rollback"]:
                assert client.get(endpoint + sandbox + action).status_code == 405
            bad_bodies = [dict(plan, database="lavkasp_lavka"), dict(plan, executor="custom"),
                          {"selections": [{"target_id": target_id, "base_50": "88.20", "database": "other"}]}]
            for body in bad_bodies:
                response = client.post(endpoint + sandbox + "plan", json=body, headers={"Origin": origin})
                assert response.status_code == 422, response.text
            payloads = {"plan": plan, "apply": {"plan_id": "a" * 32, "plan_hash": "b" * 64},
                        "rollback": {"plan_id": "c" * 32, "plan_hash": "d" * 64}}
            jobs = []
            for operation, body in payloads.items():
                response = client.post(endpoint + sandbox + operation, json=body, headers={"Origin": origin})
                responses.append(response)
                assert response.status_code == 202, (operation, response.status_code, response.text)
                job = response.json()
                assert job["state"] == "queued" and job["operation"] == operation and job["database"] == "lavkasp_test"
                assert len(job["job_id"]) == 32
                jobs.append(job["job_id"])
                response = client.get(endpoint + sandbox + "jobs/" + job["job_id"])
                responses.append(response)
                assert response.status_code == 200 and response.json()["state"] == "queued"
                assert "request_payload" not in response.json() and "result" not in response.json()
                assert client.post(endpoint + sandbox + "jobs/" + job["job_id"], json={}).status_code == 405
            for path in [sandbox + "status", "/api/competitors"]:
                response = client.get(endpoint + path)
                responses.append(response)
                assert response.status_code == 200, response.text
                pricing = response.json() if path.endswith("status") else response.json()["sandbox_pricing"]
                assert {job["job_id"] for job in pricing["recent_jobs"]} == set(jobs)
                assert all(job["state"] == "queued" for job in pricing["recent_jobs"])
            assert all(SECRET not in response.text and '"password"' not in response.text for response in responses)
            assert not (data / "sandbox-prices.sqlite3").exists(), "HTTP must not open the price journal or MySQL"
            assert not (data / "analytics.sqlite3").exists(), "Sandbox job routes must work before sales sync"
            with sqlite3.connect(data / "sandbox-price-jobs.sqlite3") as db:
                rows = db.execute("SELECT operation,state,request_payload FROM sandbox_price_jobs ORDER BY rowid").fetchall()
                assert [row[:2] for row in rows] == [(operation, "queued") for operation in payloads]
            assert (data / "sandbox-price-jobs.sqlite3").stat().st_mode & 0o777 == 0o600
            assert digest(protected) == before, "HTTP must preserve source configs and existing private data"
            client.post(endpoint + "/api/logout", json={})
            assert client.get(endpoint + sandbox + "jobs/" + jobs[0]).status_code == 401
        print(json.dumps({"php": IMAGE, "real_http_queue_only": "passed", "private_auth_origin_methods": "passed",
                          "no_mysql_or_price_journal": "passed", "status_and_redaction": "passed",
                          "shop_and_source_preserved": "passed"}))
    finally:
        subprocess.run(["docker", "rm", "-f", NAME], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        server.wait(timeout=15)
