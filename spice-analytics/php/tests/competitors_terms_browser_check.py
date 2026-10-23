"""Commercial-terms matrix: synthetic saved pages only, no external requests."""
from copy import deepcopy
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import tempfile
import time

import httpx
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT), str(ROOT / "tests")]
from test_production import snapshot
from sync_mysql import apply_snapshot

IMAGE = os.environ.get("ANALYTICS_PHP_IMAGE", "lavka-php73-dev")
ARTIFACTS = Path(os.environ.get("ANALYTICS_TEST_OUTPUT", "/workspace/work/competitors/terms-browser"))
ARTIFACTS.mkdir(parents=True, exist_ok=True)
SEED = r'''
require "php/src/Auth.php";
require "php/src/Competitors.php";
$input=json_decode(stream_get_contents(STDIN),true);
\Lavka\Auth::setPassword("/test-data",$input["password"]);
$db=\Lavka\competitors_db("/test-data");
$db->exec("UPDATE competitor_targets SET enabled=0");
$hosts=["lavka"=>"lavka-speciy.com","deluxe"=>"deluxe.com.ua","spicery"=>"spiceryshop.com.ua","banka"=>"banka-speciy.in.ua","specii"=>"www.specii.in.ua"];
function terms($source,$suffix,$text,$checkedAt) {
    global $db,$hosts;
    $target=\Lavka\competitors_save_target($db,["source_id"=>$source,"kind"=>"terms","url"=>"https://".$hosts[$source]."/synthetic-terms-".$suffix,"label"=>"Синтетические условия ".$source]);
    \Lavka\competitors_record_attempt($db,$target["id"],["kind"=>"terms","title"=>$target["label"],"text"=>$text,"offers"=>[],"links"=>[],"warnings"=>[],"method"=>"synthetic"],null,$checkedAt,200);
    return $target;
}
$now=gmdate("Y-m-d\TH:i:s\Z");
$old=gmdate("Y-m-d\TH:i:s\Z",time()-72*3600);
terms("lavka","own","Минимальный заказ — 300 грн. Бесплатная доставка Новой почтой в отделение от 2500 грн. Оплата картой или наложенным платежом.",$now);
terms("deluxe","delivery","Минимальный заказ — 200 грн. Бесплатная доставка Новой почтой в отделение от 2000 грн. Оплата картой или наложенным платежом. <img src=x onerror=window.termsXss=1>",$now);
$stale=terms("spicery","old","Минимальный заказ — 100 грн. Бесплатная доставка Новой почтой в отделение от 1500 грн.",$old);
\Lavka\competitors_record_attempt($db,$stale["id"],null,"timeout",$now,null);
terms("banka","conflict-a","Минимальный заказ — 400 грн.",$now);
terms("banka","conflict-b","Минимальный заказ — 450 грн.",$now);
terms("deluxe","discount","Скидка 10% на первый заказ за подписку на рассылку.",$now);
echo "ok";
'''


def row(page, row_id):
    return page.locator(f".competitor-terms-table tbody tr[data-terms-row='{row_id}']")


def cell(page, row_id, source):
    return row(page, row_id).locator(f"td[data-terms-source='{source}']")


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
    name = "lavka-terms-browser-" + secrets.token_hex(5)
    docker = ["docker", "run", "--rm", "--user", f"{os.getuid()}:{os.getgid()}",
              "-v", str(ROOT) + ":/app:ro", "-v", temporary + ":/test-data",
              "-e", "ANALYTICS_DATA_DIR=/test-data", "-e", "ANALYTICS_BASE_PATH=/analytics"]
    subprocess.run(docker + ["-i", IMAGE, "php", "-r", SEED],
                   input=json.dumps({"password": password}).encode(), capture_output=True, check=True)
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    server = subprocess.Popen(docker + ["--name", name, "-p", f"127.0.0.1:{port}:8000", IMAGE,
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
            raise RuntimeError("Synthetic terms server did not start")
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(executable_path="/usr/bin/chromium", args=["--no-sandbox"])
            page = browser.new_page(viewport={"width": 1440, "height": 1000}, has_touch=True)
            errors, external, mutations = [], [], []
            page.on("pageerror", lambda error: errors.append(str(error)))
            page.on("request", lambda request: external.append(request.url) if request.url.startswith(("http:", "https:")) and not request.url.startswith(origin + "/") else None)
            page.goto(base + "/")
            page.fill("#password", password)
            page.click("button[type=submit]")
            page.click("[data-view=customers]")
            page.wait_for_selector(".customer-table")
            page.on("request", lambda request: mutations.append(request.method + " " + request.url) if request.method in ["POST", "PUT", "PATCH", "DELETE"] else None)
            page.click("[data-view=competitors]")
            page.wait_for_selector("#competitor-tab-terms")
            page.click("#competitor-tab-terms")
            page.wait_for_selector(".competitor-terms-table")
            assert page.locator(".competitor-terms-table thead th").count() == 6
            assert page.locator(".competitor-watcher").count() == 0, "Cards cannot replace the comparison matrix"
            assert row(page, "minimum_order").count() == 1
            assert "200" in cell(page, "minimum_order", "deluxe").inner_text()
            assert cell(page, "minimum_order", "deluxe").locator(".competitor-terms-best").count() == 1
            assert cell(page, "minimum_order", "spicery").locator(".competitor-terms-best").count() == 0
            assert cell(page, "minimum_order", "spicery").get_attribute("data-terms-state") == "error"
            assert "100" in cell(page, "minimum_order", "spicery").inner_text(), "Previous successful fact remains visible"
            assert cell(page, "minimum_order", "banka").get_attribute("data-terms-state") == "conflict"
            assert cell(page, "minimum_order", "banka").locator(".competitor-terms-best").count() == 0
            assert cell(page, "minimum_order", "specii").locator(".competitor-terms-dash").count() == 1
            assert cell(page, "free_delivery_np_branch", "deluxe").locator(".competitor-terms-best").count() == 1
            assert row(page, "first_order_discount").locator(".competitor-terms-best").count() == 0
            assert "первый" in cell(page, "first_order_discount", "deluxe").inner_text().lower()
            assert page.evaluate("window.termsXss") is None
            minimum_button = cell(page, "minimum_order", "deluxe").locator(".competitor-terms-open")
            minimum_button.focus()
            page.keyboard.press("Enter")
            page.wait_for_selector("#drawer[open] .competitor-terms-proof")
            assert "Минимальный заказ" in page.locator(".competitor-terms-proof blockquote").first.inner_text()
            source_link = page.locator(".competitor-terms-proof a").first
            assert source_link.get_attribute("href").startswith("https://deluxe.com.ua/")
            assert "noopener" in source_link.get_attribute("rel")
            assert page.locator(".competitor-terms-detail [data-comp-action=history]").count() >= 1
            assert page.locator(".competitor-terms-detail [data-comp-action=edit]").count() >= 1
            page.click("[data-action=close-dialog]")
            page.screenshot(path=str(ARTIFACTS / "terms-desktop.png"), full_page=True)
            for width in [390, 320]:
                page.set_viewport_size({"width": width, "height": 844})
                assert page.evaluate("document.documentElement.scrollWidth <= innerWidth + 1"), f"Document overflow at {width}px"
                viewport = page.locator(".competitor-terms-scroll")
                assert viewport.evaluate("el=>el.scrollWidth>el.clientWidth"), "The matrix must scroll internally"
                first = row(page, "minimum_order").locator("th")
                before = first.bounding_box()["x"]
                viewport.evaluate("el=>el.scrollLeft=480")
                assert abs(first.bounding_box()["x"] - before) <= 1, "Criterion stays visible while browsing shops"
                assert cell(page, "minimum_order", "specii").locator(".competitor-terms-open").evaluate("el=>el.getBoundingClientRect().height>=44")
                assert float(cell(page, "minimum_order", "deluxe").locator(".competitor-terms-value").evaluate("el=>getComputedStyle(el).fontSize").replace("px", "")) >= 14
                viewport.evaluate("el=>el.scrollLeft=0")
                cell(page, "minimum_order", "specii").locator(".competitor-terms-open").click()
                assert page.locator("#drawer[open]").count() == 1
                assert page.locator(".competitor-terms-detail [data-comp-action=add]").count() == 1
                assert page.evaluate("document.documentElement.scrollWidth <= innerWidth + 1")
                page.click("[data-action=close-dialog]")
                viewport.evaluate("el=>el.scrollLeft=0")
                page.screenshot(path=str(ARTIFACTS / f"terms-{width}px.png"), full_page=True)
            assert not mutations, mutations
            assert not external, external
            assert not errors, errors
            browser.close()
        print(json.dumps({"terms_matrix": "passed", "source_drawer": "passed", "mobile_widths": [390, 320],
                          "external_requests": len(external), "mutations": len(mutations)}, ensure_ascii=False))
    finally:
        subprocess.run(["docker", "stop", name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        server.wait(timeout=20)
