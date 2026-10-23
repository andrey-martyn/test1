"""Sandbox price-review UI checks with synthetic orders and mocked test-only APIs.

No MySQL server, shop credentials or real price writes are used here. The PHP
pricing checks exercise the independent server-side database restrictions.
"""
from copy import deepcopy
from datetime import datetime, timedelta, timezone
from decimal import Decimal, ROUND_HALF_UP
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
from urllib.parse import parse_qs, urlsplit

import httpx
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT), str(ROOT / "tests")]
from test_production import snapshot
from sync_mysql import apply_snapshot

IMAGE = os.environ.get("ANALYTICS_PHP_IMAGE", "lavka-php73-dev")
ARTIFACTS = Path(os.environ.get("ANALYTICS_TEST_OUTPUT", "/workspace/work/competitors/sandbox-browser"))
ARTIFACTS.mkdir(parents=True, exist_ok=True)
NAME = "lavka-sandbox-browser-" + secrets.token_hex(5)
SEED = r'''
require "php/src/Auth.php";
require "php/src/Competitors.php";
$input=json_decode(stream_get_contents(STDIN),true);
\Lavka\Auth::setPassword("/test-data",$input["password"]);
$db=\Lavka\competitors_db("/test-data");
$db->exec("UPDATE competitor_targets SET enabled=0");
function watch($db,$source,$id,$name,$group) {
  $url=$source==="lavka"?"https://lavka-speciy.com/index.php?route=product/product&product_id=".$id:
    "https://deluxe.com.ua/synthetic-sandbox-".$id;
  return \Lavka\competitors_save_target($db,["source_id"=>$source,"kind"=>"product","url"=>$url,
    "label"=>$name,"enabled"=>true,"match_group"=>$group,"match_confirmed"=>true,"pack_label"=>"50 г / 200 г"]);
}
function record_price($db,$target,$price) {
  $offers=[];
  foreach([50=>$price,200=>"300"] as $grams=>$amount)$offers[]=["key"=>(string)$grams,"label"=>$grams." г",
    "price"=>$amount,"old_price"=>null,"currency"=>"UAH","grams"=>$grams,"unit"=>"pack",
    "condition"=>"","availability"=>"in_stock","min_quantity"=>1];
  \Lavka\competitors_record_attempt($db,$target["id"],["kind"=>"product","title"=>$target["label"],
    "offers"=>$offers,"text"=>"","links"=>[],"warnings"=>[],"method"=>"synthetic"],null,gmdate("Y-m-d\TH:i:s\Z"),200);
}
$main=watch($db,"lavka",999501,"Песочница — кориандр","sandbox-main");
$other=watch($db,"deluxe",999501,"Песочница — предложение конкурента","sandbox-main");
$skip=watch($db,"lavka",999502,"Песочница — неподдержанный товар","sandbox-skipped");
$skipOther=watch($db,"deluxe",999502,"Песочница — второй конкурент","sandbox-skipped");
record_price($db,$main,"100"); record_price($db,$other,"90");
record_price($db,$skip,"100"); record_price($db,$skipOther,"95");
echo json_encode(["main"=>$main["id"],"skipped"=>$skip["id"]]);
'''


def row(page, group="sandbox-main", grams=50):
    return page.locator(f".competitor-matrix tbody tr[data-matrix-row='{group}|{grams}']")


def price_digest(directory):
    with sqlite3.connect(directory / "competitors.sqlite3") as db:
        values = db.execute("SELECT id,observation FROM competitor_targets ORDER BY id").fetchall()
    return hashlib.sha256(json.dumps(values).encode()).hexdigest()


def money(value):
    return str(Decimal(str(value)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP))


with tempfile.TemporaryDirectory() as temporary:
    data = Path(temporary)
    original = snapshot()
    rows = {table: {} for table in original}
    rows["oc_order_status"] = original["oc_order_status"]
    order = deepcopy(original["oc_order"]["1"])
    order.update(order_id="101", customer_id="101", date_added="2026-01-01 12:00:00",
                 date_modified="2026-01-01 12:00:00", firstname="Синтетический",
                 lastname="Покупатель", email="synthetic@example.invalid", telephone="+380960000101")
    rows["oc_order"]["101"] = order
    line = deepcopy(original["oc_order_product"]["1"])
    line.update(order_product_id="101", order_id="101")
    rows["oc_order_product"]["101"] = line
    option = deepcopy(original["oc_order_option"]["1"])
    option.update(order_option_id="101", order_id="101", order_product_id="101")
    rows["oc_order_option"]["101"] = option
    apply_snapshot(data / "analytics.sqlite3", rows, "synthetic")
    password = secrets.token_urlsafe(24)
    docker = ["docker", "run", "--rm", "--user", f"{os.getuid()}:{os.getgid()}",
              "-v", str(ROOT) + ":/app:ro", "-v", temporary + ":/test-data",
              "-e", "ANALYTICS_DATA_DIR=/test-data", "-e", "ANALYTICS_BASE_PATH=/analytics"]
    seeded = subprocess.run(docker + ["-i", IMAGE, "php", "-r", SEED],
                            input=json.dumps({"password": password}).encode(), capture_output=True, check=True)
    ids = json.loads(seeded.stdout)
    original_prices = price_digest(data)
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    server = subprocess.Popen(docker + ["--name", NAME, "-p", f"127.0.0.1:{port}:8000", IMAGE,
                                       "php", "-S", "0.0.0.0:8000", "-t", "php/public", "php/public/index.php"],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    origin = f"http://127.0.0.1:{port}"
    base = origin + "/analytics"
    try:
        for _ in range(600):
            try:
                if httpx.get(base + "/index.php?route=/health", trust_env=False, timeout=2).status_code == 200:
                    break
            except httpx.TransportError:
                pass
            time.sleep(.1)
        else:
            raise RuntimeError("Synthetic sandbox server did not start")
        # These requests reach the real PHP routes before browser mocks exist.
        # There is no sandbox marker or source configuration in this fixture.
        for action in ["status", "plan", "apply", "rollback"]:
            response = httpx.get(base + "/index.php?route=/api/competitors/sandbox/" + action,
                                 trust_env=False, timeout=5)
            assert response.status_code == 401, (action, response.status_code)
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(executable_path="/usr/bin/chromium", args=["--no-sandbox"])
            page = browser.new_page(viewport={"width": 1440, "height": 1000}, has_touch=True)
            page_errors, external, requests = [], [], []
            page.on("pageerror", lambda error: page_errors.append(str(error)))
            page.on("request", lambda request: external.append(request.url) if
                    request.url.startswith(("http:", "https:")) and not request.url.startswith(origin + "/") else None)
            page.goto(base + "/")
            page.fill("#password", password)
            page.click("button[type=submit]")
            page.click("[data-view=customers]")
            page.wait_for_selector(".customer-table")
            real_status = page.request.get(base + "/index.php?route=/api/competitors/sandbox/status")
            assert real_status.status == 200
            assert real_status.json()["enabled"] is False and real_status.json()["can_write"] is False
            legit_origin = {"Origin": origin}
            real_endpoints = {action: base + "/index.php?route=/api/competitors/sandbox/" + action
                              for action in ["status", "plan", "apply", "rollback"]}
            valid_payloads = {"plan": {"selections": [{"target_id": ids["main"], "base_50": "88.20"}]},
                              "apply": {"plan_id": "a" * 32, "plan_hash": "b" * 64},
                              "rollback": {"plan_id": "a" * 32, "plan_hash": "b" * 64}}
            for action, payload in valid_payloads.items():
                blocked = page.request.post(real_endpoints[action], data=payload, headers=legit_origin)
                assert blocked.status == 403, (action, blocked.status, blocked.text())
                assert blocked.json()["error_code"] == "sandbox_not_configured"
                hostile = page.request.post(real_endpoints[action], data=payload,
                                            headers={"Origin": "https://hostile.example.invalid"})
                assert hostile.status == 403, (action, hostile.status)
                bad_body = page.request.post(real_endpoints[action], data={**payload, "database": "lavkasp_lavka"},
                                             headers=legit_origin)
                assert bad_body.status == 422, (action, bad_body.status)
                wrong_method = page.request.get(real_endpoints[action])
                assert wrong_method.status == 405, (action, wrong_method.status)
            assert page.request.post(real_endpoints["status"], data={}, headers=legit_origin).status == 405
            invalid_types = [("plan", {"selections": "not-an-array"}),
                             ("apply", {"plan_id": 123, "plan_hash": "b" * 64}),
                             ("rollback", {"plan_id": "a" * 32, "plan_hash": None})]
            for action, payload in invalid_types:
                invalid = page.request.post(real_endpoints[action], data=payload, headers=legit_origin)
                assert invalid.status == 422, (action, invalid.status)
            for forbidden_file in ["source.json", "sandbox.json", "sandbox-prices.sqlite3", ".sandbox-pricing.lock",
                                   "own-catalog.json", ".own-catalog.lock"]:
                assert not (data / forbidden_file).exists(), forbidden_file
            assert price_digest(data) == original_prices
            real_overview = page.request.get(base + "/index.php?route=/api/competitors").json()
            overview = deepcopy(real_overview)
            disabled = {"environment": "sandbox", "database": "lavkasp_test", "enabled": False,
                        "can_write": False, "reason": "sandbox_not_configured"}
            status = dict(disabled)
            scenario = {"plan": None, "number": 0, "outcome": "applied", "include_second": False,
                        "expired": False, "queue_next": False, "hold_job": False, "job_error": None}
            jobs, job_requests = {}, []

            def fulfill(route, body, code=200):
                route.fulfill(status=code, content_type="application/json", body=json.dumps(body))

            def queued_or_result(route, result, operation):
                if not scenario["queue_next"]:
                    fulfill(route, result)
                    return
                scenario["queue_next"] = False
                job_id = f"{len(jobs) + 1000:032x}"
                jobs[job_id] = {"polls": 0, "result": result, "operation": operation,
                                "error_code": scenario["job_error"]}
                status.setdefault("recent_jobs", []).insert(0, {"job_id": job_id, "operation": operation,
                    "state": "queued", "created_at": datetime.now(timezone.utc).isoformat()})
                fulfill(route, {"state": "queued", "job_id": job_id, "database": "lavkasp_test"})

            def intercept(route):
                path = parse_qs(urlsplit(route.request.url).query).get("route", [""])[0]
                if path == "/api/competitors":
                    body = deepcopy(overview)
                    body["sandbox_pricing"] = dict(status)
                    fulfill(route, body)
                elif path == "/api/competitors/sandbox/status":
                    fulfill(route, dict(status))
                elif path.startswith("/api/competitors/sandbox/jobs/"):
                    assert route.request.method == "GET"
                    job_id = path.rsplit("/", 1)[1]
                    job_requests.append(job_id)
                    job = jobs[job_id]
                    job["polls"] += 1
                    job_state = "queued" if scenario["hold_job"] or job["polls"] <= 2 else "error" if job["error_code"] else "done"
                    for recent in status.get("recent_jobs", []):
                        if recent["job_id"] == job_id:
                            recent["state"] = job_state
                    response = {"state": job_state, "job_id": job_id}
                    if job_state == "done":
                        response["result"] = job["result"]
                    if job_state == "error":
                        response["error_code"] = job["error_code"]
                    fulfill(route, response)
                elif path.startswith("/api/competitors/sandbox/"):
                    action = path.rsplit("/", 1)[1]
                    body = json.loads(route.request.post_data or "{}")
                    requests.append({"action": action, "method": route.request.method, "body": body})
                    assert route.request.method == "POST"
                    assert status.get("environment") == "sandbox" and status.get("database") == "lavkasp_test"
                    assert status.get("enabled") is True and status.get("can_write") is True
                    if action == "plan":
                        assert set(body) == {"selections"}
                        assert all(set(item) == {"target_id", "base_50"} for item in body["selections"])
                        scenario["number"] += 1
                        n = scenario["number"]
                        plan_rows, skipped = [], []
                        for item in body["selections"]:
                            target = next(t for t in overview["targets"] if t["id"] == item["target_id"])
                            if target["id"] == ids["skipped"] and not scenario["include_second"]:
                                skipped.append({"product_id": 999502, "target_id": target["id"],
                                                "reason": "sandbox_product_unsupported"})
                                continue
                            b = Decimal(item["base_50"])
                            packs = [{"grams": 50, "before": "100.00", "after": money(b)},
                                     {"grams": 200, "before": "300.00", "after": money(b * Decimal("3.6"))}]
                            plan_rows.append({"target_id": target["id"], "product_id": 999501 if target["id"] == ids["main"] else 999502,
                                              "label": target["label"], "base_50": money(b), "before_base_50": "100.00",
                                              "url": "https://test.lavka-speciy.com/index.php?route=product%2Fproduct&product_id=999501",
                                              "packs": packs})
                        plan = {"state": "planned", "environment": "sandbox", "database": "lavkasp_test",
                                "plan_id": f"{n:032x}", "plan_hash": f"{n:064x}", "rows": plan_rows,
                                "skipped": skipped, "purchase_cost_available": False,
                                "expires_at": (datetime.now(timezone.utc) + timedelta(minutes=-1 if scenario["expired"] else 30)).strftime("%Y-%m-%dT%H:%M:%SZ")}
                        scenario["plan"] = plan
                        queued_or_result(route, plan, "plan")
                    else:
                        plan = scenario["plan"]
                        assert body == {"plan_id": plan["plan_id"], "plan_hash": plan["plan_hash"]}
                        if action == "apply":
                            state = scenario["outcome"]
                            queued_or_result(route, {"state": state, "database": "lavkasp_test", "plan_id": plan["plan_id"],
                                            "applied_products": 1 if state != "conflict" else 0,
                                            "failed_products": 1 if state != "applied" else 0,
                                            "changed_records": 3 if state != "conflict" else 0,
                                            "rollback_available": state != "conflict", "own_catalog_refresh": "ok"}, "apply")
                        elif action == "rollback":
                            queued_or_result(route, {"state": "rolled_back", "database": "lavkasp_test", "plan_id": plan["plan_id"],
                                            "restored_changes": 3, "conflicting_changes": 0,
                                            "rollback_available": False, "own_catalog_refresh": "ok"}, "rollback")
                        else:
                            raise AssertionError("Unexpected sandbox action: " + action)
                else:
                    route.continue_()

            page.route("**/index.php?route=**", intercept)
            page.click("[data-view=competitors]")
            page.wait_for_selector(".competitor-matrix")
            page.select_option("#competitor-matrix-pack", "50")
            assert page.locator(".competitor-sandbox-panel").get_attribute("data-sandbox-enabled") == "false"
            assert page.locator("[data-comp-action=sandbox-apply]").count() == 0
            row(page).locator("[data-matrix-select]").check()
            page.click("[data-comp-action=matrix-preview]")
            expected_chain = {20: 44.10, 50: 88.20, 200: 317.52, 500: 661.50, 1000: 1190.70}
            for grams, expected in expected_chain.items():
                assert float(page.locator(f"[data-chain-row='sandbox-main|50'] [data-chain-grams='{grams}']").get_attribute("data-chain-price")) == expected
            assert not requests
            page.select_option("#competitor-matrix-pack", "all")
            assert row(page, grams=200).locator("[data-matrix-select]").is_disabled()
            assert "Расчёт от 50 или 500 г" in row(page, grams=200).inner_text()
            page.select_option("#competitor-matrix-pack", "50")

            # A client-visible database name alone cannot enable a write control.
            for invalid_status in [{**disabled, "enabled": True, "can_write": True, "database": "lavkasp_lavka"},
                                   {**disabled, "enabled": True, "can_write": True, "environment": "production"}]:
                status.clear(); status.update(invalid_status)
                page.click("[data-comp-action=refresh]")
                page.wait_for_function("() => !competitorState.loading")
                assert page.locator(".competitor-sandbox-panel").get_attribute("data-sandbox-enabled") == "false"
                assert page.locator("[data-comp-action=sandbox-apply]").count() == 0
            assert not requests

            status.clear(); status.update({**disabled, "enabled": True, "can_write": True, "reason": None})
            page.click("[data-comp-action=refresh]")
            page.wait_for_function("() => !competitorState.loading")
            assert page.locator(".competitor-sandbox-panel").get_attribute("data-sandbox-enabled") == "true"
            row(page, "sandbox-skipped").locator("[data-matrix-select]").check()
            page.click("[data-comp-action=matrix-preview]")
            scenario["queue_next"] = True
            page.click("[data-comp-action=sandbox-plan]")
            page.wait_for_selector(".competitor-sandbox-job")
            assert "Обычно до минуты" in page.locator(".competitor-sandbox-job").inner_text()
            assert page.locator("[data-comp-action=sandbox-plan]").is_disabled()
            assert not any(item["action"] == "apply" for item in requests)
            page.wait_for_selector(".competitor-server-plan")
            assert requests[-1]["action"] == "plan" and len(requests[-1]["body"]["selections"]) == 2
            server_plan = page.locator(".competitor-server-plan")
            assert server_plan.locator("tbody tr").count() == 2  # Only existing 50g and 200g packs.
            assert server_plan.locator("tbody").inner_text().count("500 г") == 0
            assert server_plan.locator(".competitor-sandbox-skipped").count() == 1
            server_plan.locator(".competitor-sandbox-skipped summary").click()
            assert "Пропущено товаров: 1" in server_plan.inner_text()
            assert page.locator("[data-comp-action=sandbox-apply]").is_disabled()
            assert not any(item["action"] == "apply" for item in requests)

            # Changing a reviewed selection cannot reuse its old hash or approval.
            page.check("#competitor-sandbox-confirm")
            row(page, "sandbox-skipped").locator("[data-matrix-select]").uncheck()
            page.wait_for_function("() => competitorState.sandboxPlan === null")
            assert page.locator("[data-comp-action=sandbox-apply]").count() == 0
            assert page.evaluate("competitorState.sandboxConfirmed") is False
            row(page, "sandbox-skipped").locator("[data-matrix-select]").check()
            page.click("[data-comp-action=matrix-preview]")
            page.click("[data-comp-action=sandbox-plan]")
            page.wait_for_selector("#competitor-sandbox-confirm")
            page.check("#competitor-sandbox-confirm")
            row(page).locator("[data-matrix-cut]").fill("3")
            page.wait_for_function("() => competitorState.sandboxPlan === null")
            assert page.locator("[data-comp-action=sandbox-apply]").count() == 0
            row(page).locator("[data-matrix-cut]").fill("2")
            page.click("[data-comp-action=matrix-preview]")
            page.click("[data-comp-action=sandbox-plan]")
            page.wait_for_selector("#competitor-sandbox-confirm")

            # A changed batch percentage also invalidates the server plan.
            page.fill("#competitor-batch-cut", "3")
            page.wait_for_function("() => competitorState.sandboxPlan === null")
            assert page.locator(".competitor-server-plan").count() == 0
            assert page.locator("[data-comp-action=sandbox-apply]").count() == 0
            page.fill("#competitor-batch-cut", "2")
            scenario["expired"] = True
            page.click("[data-comp-action=matrix-preview]")
            page.click("[data-comp-action=sandbox-plan]")
            page.wait_for_selector(".competitor-server-plan")
            assert "больше не действителен" in page.locator(".competitor-server-plan").inner_text()
            assert page.locator("#competitor-sandbox-confirm").count() == 0
            assert page.locator("[data-comp-action=sandbox-apply]").count() == 0
            assert not any(item["action"] == "apply" for item in requests)
            scenario["expired"] = False
            page.click("[data-comp-action=matrix-preview]")
            page.click("[data-comp-action=sandbox-plan]")
            page.wait_for_selector("#competitor-sandbox-confirm")
            page.check("#competitor-sandbox-confirm")
            assert page.locator("[data-comp-action=sandbox-apply]").is_enabled()
            scenario["queue_next"] = True
            page.click("[data-comp-action=sandbox-apply]")
            page.wait_for_selector(".competitor-sandbox-job")
            assert page.locator("[data-comp-action=sandbox-apply]").is_disabled()
            assert page.locator(".competitor-server-plan").count() == 1
            page.wait_for_function("() => !competitorState.sandboxBusy")
            assert requests[-1]["action"] == "apply"
            assert "Цены записаны в тестовую базу" in page.locator(".competitor-sandbox-result").inner_text()
            assert page.locator("[data-comp-action=sandbox-apply]").count() == 0
            scenario["queue_next"] = True
            page.click("[data-comp-action=sandbox-rollback]")
            page.wait_for_selector(".competitor-sandbox-job")
            page.wait_for_function("() => !competitorState.sandboxBusy")
            assert requests[-1]["action"] == "rollback"
            assert "Прежние тестовые цены восстановлены" in page.locator(".competitor-sandbox-result").inner_text()
            assert page.locator("[data-comp-action=sandbox-rollback]").count() == 0

            # Partial and conflict responses never become a blanket success.
            for outcome in ["partial", "conflict"]:
                scenario["outcome"] = outcome
                scenario["include_second"] = True
                page.click("[data-comp-action=matrix-preview]")
                page.click("[data-comp-action=sandbox-plan]")
                page.wait_for_selector("#competitor-sandbox-confirm")
                page.check("#competitor-sandbox-confirm")
                page.click("[data-comp-action=sandbox-apply]")
                page.wait_for_function("() => !competitorState.sandboxBusy")
                message = page.locator(".competitor-sandbox-result").inner_text()
                assert "частично" in message if outcome == "partial" else "изменились отдельно" in message
                assert "Цены записаны в тестовую базу" not in message
                assert page.locator("[data-comp-action=sandbox-rollback]").count() == (1 if outcome == "partial" else 0)

            # A slow job remains recoverable through GET without another POST.
            scenario["queue_next"] = True
            scenario["hold_job"] = True
            posts_before_wait = len(requests)
            page.click("[data-comp-action=matrix-preview]")
            page.click("[data-comp-action=sandbox-plan]")
            page.wait_for_selector(".competitor-sandbox-job")
            page.evaluate("() => {competitorState.sandboxJob.deadline=Date.now()-1;}")
            page.wait_for_function("() => competitorState.sandboxJob?.paused === 'timeout'")
            assert "проверьте его позже" in page.locator(".competitor-sandbox-job").inner_text()
            assert len(requests) == posts_before_wait + 1
            assert page.locator("[data-comp-action=sandbox-plan]").is_disabled()
            scenario["hold_job"] = False
            page.click(".competitor-sandbox-job [data-comp-action=sandbox-job]")
            page.wait_for_function("() => !competitorState.sandboxBusy")
            assert len(requests) == posts_before_wait + 1
            assert page.locator(".competitor-server-plan").count() == 1
            assert page.locator("[data-comp-action=sandbox-apply]").is_disabled()
            assert len(job_requests) >= 10
            assert all(job["polls"] >= 3 for job in jobs.values())

            # A failed worker preflight preserves the reviewed plan, but needs a
            # fresh explicit approval before retrying the write action.
            scenario["queue_next"] = True
            scenario["job_error"] = "sandbox_connection_failed"
            page.check("#competitor-sandbox-confirm")
            checked_plan_id = page.locator(".competitor-server-plan").get_attribute("data-plan-id")
            page.click("[data-comp-action=sandbox-apply]")
            page.wait_for_selector(".competitor-sandbox-job")
            page.wait_for_function("() => !competitorState.sandboxBusy")
            assert page.locator(".competitor-server-plan").get_attribute("data-plan-id") == checked_plan_id
            assert page.locator("#competitor-sandbox-confirm").is_checked() is False
            assert "Не удалось подключиться к тестовой базе" in page.locator(".competitor-form-error").inner_text()
            assert page.locator("[data-comp-action=sandbox-apply]").is_disabled()
            scenario["job_error"] = None

            # Leaving the section pauses local polling. A full reload recovers
            # the server job through history and GET without repeating its POST.
            scenario["queue_next"] = True
            scenario["hold_job"] = True
            page.click("[data-comp-action=matrix-preview]")
            page.click("[data-comp-action=sandbox-plan]")
            page.wait_for_selector(".competitor-sandbox-job")
            recovering_id = page.locator(".competitor-sandbox-job").get_attribute("data-job-id")
            recovering_posts = len(requests)
            page.click("[data-view=overview]")
            page.wait_for_function("() => competitorState.sandboxJob?.paused === 'left'")
            assert len(requests) == recovering_posts
            page.reload()
            page.click("[data-view=customers]")
            page.wait_for_selector(".customer-table")
            page.click("[data-view=competitors]")
            page.wait_for_selector(".competitor-matrix")
            page.select_option("#competitor-matrix-pack", "50")
            assert page.locator("[data-comp-action=sandbox-plan]").is_disabled()
            page.locator(".competitor-sandbox-jobs summary").click()
            scenario["hold_job"] = False
            page.locator(f"[data-comp-action=sandbox-job][data-job-id='{recovering_id}']").click()
            page.wait_for_function("() => !competitorState.sandboxBusy")
            assert len(requests) == recovering_posts
            assert "заново выберите товары" in page.locator(".competitor-server-plan").inner_text()
            assert page.locator("[data-comp-action=sandbox-apply]").count() == 0

            for code, expected in [("sandbox_job_interrupted", "Цены могли измениться"),
                                   ("sandbox_job_expired", "Запросите новый план"),
                                   ("sandbox_job_queue_full", "Очередь занята"),
                                   ("sandbox_job_not_found", "проверьте журнал операций")]:
                assert expected in page.evaluate("code=>competitorSandboxMessage(code)", code)

            # The test environment cannot reuse a production HTTP observation,
            # including an old SEO URL with no product ID or a missing SQL product.
            before_isolation = page.evaluate("() => JSON.parse(JSON.stringify(competitorState.data))")
            isolated = deepcopy(before_isolation)
            isolated["environment"] = {"mode": "sandbox", "database": "lavkasp_test"}
            main_target = next(t for t in isolated["targets"] if t["id"] == ids["main"])
            seo_target = deepcopy(main_target)
            seo_target.update(id=999909, url="https://lavka-speciy.com/legacy-seo-product",
                              label="Песочница — без ID", match_group="sandbox-unmapped")
            isolated["targets"].append(seo_target)
            sql_offers = deepcopy(main_target["observation"]["offers"])
            for offer in sql_offers:
                if offer["grams"] == 50:
                    offer["price"] = "77.00"
            isolated["own_catalog"] = {"state": "ok", "source_database": "lavkasp_test",
                "environment": "sandbox", "last_success": isolated["server_now"], "products": [{
                    "product_id": 999501, "target_id": ids["main"], "label": main_target["label"],
                    "url": "https://lavka-speciy.com/legacy-production-url", "offers": sql_offers}]}
            page.evaluate("data => {competitorState.data=data;competitorState.tab='products';competitorsRender();}", isolated)
            assert row(page).locator("td[data-source=lavka]").get_attribute("data-price") == "77.00", row(page).inner_text()
            assert "test.lavka-speciy.com" in row(page).locator("td[data-source=lavka] a").get_attribute("href")
            assert "Товар отсутствует в снимке базы" in row(page, "sandbox-skipped").inner_text()
            assert "Нужен ID тестового товара" in row(page, "sandbox-unmapped").inner_text()
            own_entries = page.evaluate("() => competitorState.data.targets.filter(competitorOwn).map(t=>competitorMatrixEntries(t))")
            assert all(entry["catalog"] and not entry["target"].get("queued") for entry in own_entries)
            assert all(not entry["offers"] for entry in own_entries if entry["target"]["id"] != ids["main"])

            # The storefront's whole-hryvnia display never replaces the precise
            # amount used by the formula or by a reviewed database update.
            rounding_state = page.evaluate("() => ({selected:competitorState.selected,preview:competitorState.preview,sandboxPlan:competitorState.sandboxPlan})")
            whole_currency = deepcopy(isolated)
            whole_currency["own_catalog"]["currency_decimal_places"] = 0
            whole_currency["own_catalog"]["products"][0]["currency_decimal_places"] = 0
            for offer in whole_currency["own_catalog"]["products"][0]["offers"]:
                raw = "125.9800" if offer["grams"] == 50 else "299.5100"
                offer["price"] = "126" if offer["grams"] == 50 else "300"
                offer["source"] = "mysql_catalog"
                offer["provenance"] = {"raw_price": raw, "currency_decimal_places": 0}
            whole_plan = {"state": "planned", "database": "lavkasp_test", "plan_id": "c" * 32, "plan_hash": "d" * 64,
                "expires_at": (datetime.now(timezone.utc) + timedelta(minutes=30)).strftime("%Y-%m-%dT%H:%M:%SZ"),
                "rows": [{"product_id": 999501, "label": main_target["label"], "currency_decimal_places": 0,
                          "packs": [{"grams": 50, "before": "125.9800", "display_before": "126", "after": "88.20", "display_after": "88"},
                                    {"grams": 200, "before": "299.5100", "display_before": "300", "after": "317.52", "display_after": "318"}]}], "skipped": []}
            rounding_posts = len(requests)
            page.evaluate("""input => {competitorState.data=input.data;competitorState.selected={'sandbox-main|50':true};
                competitorState.preview=true;competitorState.sandboxPlan=input.plan;competitorsRender();}""",
                {"data": whole_currency, "plan": whole_plan})
            whole_cell = row(page).locator("td[data-source=lavka]")
            assert whole_cell.get_attribute("data-price") == "126.00"
            assert "125,98" in whole_cell.locator(".competitor-raw-price").inner_text()
            chain_base = page.locator("[data-chain-row='sandbox-main|50'] [data-chain-grams='50']")
            assert chain_base.get_attribute("data-chain-price") == "88.20"
            assert chain_base.locator(".competitor-chain-display").get_attribute("data-chain-display") == "88"
            assert page.locator("[data-chain-grams='200'] .competitor-chain-display").get_attribute("data-chain-display") == "318"
            rounded_plan = page.locator(".competitor-server-plan")
            assert "Витрина округляет до целой гривны" in rounded_plan.locator(".competitor-rounding-note").inner_text()
            assert rounded_plan.locator("[data-sandbox-price='88.20']").get_attribute("data-sandbox-display-price") == "88"
            assert "88,2" in rounded_plan.locator("[data-sandbox-price='88.20'] .competitor-sandbox-raw-price").inner_text()
            assert rounded_plan.locator("[data-sandbox-price='317.52']").get_attribute("data-sandbox-display-price") == "318"
            whole_cell.locator("[data-comp-action=matrix-detail]").click()
            page.wait_for_selector("#drawer[open]")
            assert "125,98" in " ".join(page.locator("#drawer-content .competitor-raw-price").all_inner_texts())
            page.click("[data-action=close-dialog]")
            caps = page.evaluate("""() => ["99.6000","100.4900","100.4999",null].map(raw=>{
                const current=competitorMatrixRows().find(row=>row.key==='sandbox-main|50');
                current.cells.lavka={...current.cells.lavka,price:100,offer:{...current.cells.lavka.offer,
                    provenance:{raw_price:raw,currency_decimal_places:0}}};current.competitorMinimum=110;
                return competitorSuggestion(current);})""")
            assert [item["price"] for item in caps] == [99.6, 100.49, 100.49, 100]
            assert all(item["keep"] for item in caps)
            assert len(requests) == rounding_posts
            page.evaluate("""input => {competitorState.data=input.data;competitorState.selected=input.state.selected;
                competitorState.preview=input.state.preview;competitorState.sandboxPlan=input.state.sandboxPlan;competitorsRender();}""",
                {"data": isolated, "state": rounding_state})
            page.click("[data-comp-action=tab][data-tab=links]")
            assert page.locator(".competitor-product-card.own [data-comp-action=queue]").count() == 0
            assert page.locator(".competitor-product-card.own .competitor-price-line strong").count() == 2
            assert all("test.lavka-speciy.com" in link for link in
                       page.locator(".competitor-product-card.own h3 a").evaluate_all("els=>els.map(el=>el.href)"))
            assert "Укажите ссылку на тестовый товар с product_id" in page.locator(".competitor-product-card.own").all_inner_texts()[-1]
            for invalid_mode, invalid_database in [("blocked", "lavkasp_test"), ("sandbox", "lavkasp_lavka")]:
                isolated["environment"]["mode"] = invalid_mode
                isolated["own_catalog"]["source_database"] = invalid_database
                page.evaluate("data => {competitorState.data=data;competitorsRender();}", isolated)
                assert page.locator(".competitor-product-card.own .competitor-price-line strong").count() == 0
                assert "Нужен подтверждённый снимок тестовой базы" in page.locator(".competitor-product-card.own").first.inner_text()
            page.evaluate("data => {competitorState.data=data;competitorState.tab='products';competitorsRender();}", before_isolation)

            # Contrast is measured against the actual composited cell background.
            contrast = page.evaluate("""() => {
                const parse=s=>s.match(/[\\d.]+/g).map(Number),blend=(fg,bg)=>{
                    const a=fg.length>3?fg[3]:1;return fg.slice(0,3).map((c,i)=>c*a+bg[i]*(1-a));
                },lum=rgb=>rgb.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;})
                    .reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0);
                const selectors=['.competitor-cell-detail','.competitor-cell-currency','.competitor-row-position',
                    '.competitor-report-note','.competitor-scope-note','.competitor-footer','.competitor-sandbox-cost'];
                return selectors.map(selector=>{const el=document.querySelector(selector),style=getComputedStyle(el);
                    const ancestry=[];for(let p=el;p;p=p.parentElement)ancestry.unshift(p);
                    let bg=[255,255,255];ancestry.forEach(p=>bg=blend(parse(getComputedStyle(p).backgroundColor),bg));
                    const fg=blend(parse(style.color),bg),a=lum(fg),b=lum(bg);
                    return {selector,size:parseFloat(style.fontSize),ratio:(Math.max(a,b)+.05)/(Math.min(a,b)+.05)};
                });
            }""")
            assert all(item["size"] >= 12 and item["ratio"] >= 4.5 for item in contrast), contrast
            for width in [390, 320, 1440]:
                page.set_viewport_size({"width": width, "height": 844})
                assert page.evaluate("document.documentElement.scrollWidth <= innerWidth"), width
                assert row(page).locator(".competitor-cell-detail").first.evaluate("el=>parseFloat(getComputedStyle(el).fontSize)") >= 12
                if width <= 390:
                    assert page.locator(".competitor-matrix-scroll").evaluate("el=>el.scrollWidth>el.clientWidth")
            page.set_viewport_size({"width": 390, "height": 844})
            page.screenshot(path=str(ARTIFACTS / "sandbox-iphone13.png"), full_page=True)
            page.set_viewport_size({"width": 1440, "height": 1000})
            page.screenshot(path=str(ARTIFACTS / "sandbox-desktop.png"), full_page=True)
            assert price_digest(data) == original_prices
            assert not page_errors, page_errors
            assert not external, external
            assert all(item["method"] == "POST" for item in requests)
            browser.close()
        print(json.dumps({"php_image": IMAGE, "sandbox_gates_and_explicit_review": "passed",
                          "real_http_auth_origin_methods_and_production_block": "passed",
                          "base50_chain_and_existing_packs": "passed", "whole_currency_display_and_raw_no_raise": "passed",
                          "plan_invalidation_and_rollback": "passed",
                          "partial_conflict_honesty": "passed", "test_sql_only_own_prices": "passed",
                          "queued_cli_jobs_poll_timeout_and_retry": "passed",
                          "contrast_12px_and_mobile": "passed",
                          "real_shop_writes": 0}))
    finally:
        subprocess.run(["docker", "rm", "-f", NAME], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        server.wait(timeout=15)
