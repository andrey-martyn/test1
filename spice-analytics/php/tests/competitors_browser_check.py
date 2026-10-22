"""Synthetic browser coverage for the competitors module; never reads a shop database."""
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
ARTIFACTS = Path(os.environ.get("ANALYTICS_TEST_OUTPUT", "/workspace/work/competitors/browser"))
ARTIFACTS.mkdir(parents=True, exist_ok=True)
name = "lavka-competitors-browser-" + secrets.token_hex(5)

SEED = r'''
require "php/src/Auth.php";
require "php/src/Competitors.php";
$input = json_decode(stream_get_contents(STDIN), true);
\Lavka\Auth::setPassword("/test-data", $input["password"]);
$db = \Lavka\competitors_db("/test-data");
function target($db,$source,$suffix,$label,$kind="product") {
    return \Lavka\competitors_save_target($db, [
        "source_id"=>$source,"kind"=>$kind,
        "url"=>"https://".($source==="lavka"?"lavka-speciy.com":"deluxe.com.ua")."/synthetic-".$suffix,
        "label"=>$label,"match_group"=>$kind==="product"?"synthetic-coriander":"",
        "match_confirmed"=>$kind==="product"
    ]);
}
function offer($key,$price,$grams=100,$currency="UAH",$condition="",$min=1) {
    return ["key"=>$key,"label"=>$key,"price"=>$price,"old_price"=>null,"currency"=>$currency,
        "grams"=>$grams,"availability"=>"in_stock","min_quantity"=>$min,"unit"=>"pack","condition"=>$condition];
}
function observation($offers) {
    return ["kind"=>"product","title"=>"Синтетический кориандр","offers"=>$offers,
        "text"=>"","links"=>[],"warnings"=>[],"method"=>"synthetic"];
}
$now = gmdate("Y-m-d\TH:i:s\Z");
$prior = gmdate("Y-m-d\TH:i:s\Z",time()-3600);
$stale = gmdate("Y-m-d\TH:i:s\Z",time()-72*3600);
$own = target($db,"lavka","own","Синтетический кориандр Лавки");
\Lavka\competitors_record_attempt($db,$own["id"],observation([offer("100 г","50"),offer("500 г","200",500)]),null,$now,200);
$valid = target($db,"deluxe","valid","Синтетический кориандр конкурент");
\Lavka\competitors_record_attempt($db,$valid["id"],observation([offer("100 г","45")]),null,$prior,200);
\Lavka\competitors_record_attempt($db,$valid["id"],observation([offer("100 г","40"),offer("Оптовый пакет","20",100,"UAH","От 5 упаковок",5)]),null,$now,200);
$unknown = target($db,"deluxe","unknown","Синтетический товар неизвестный вес");
\Lavka\competitors_record_attempt($db,$unknown["id"],observation([offer("Фасовка неизвестна","30",null)]),null,$now,200);
$old = target($db,"deluxe","stale","Синтетический товар устаревшая цена");
\Lavka\competitors_record_attempt($db,$old["id"],observation([offer("100 г","10")]),null,$stale,200);
$error = target($db,"deluxe","error","Синтетический товар ошибка сайта");
\Lavka\competitors_record_attempt($db,$error["id"],observation([offer("100 г","32")]),null,$prior,200);
\Lavka\competitors_record_attempt($db,$error["id"],null,"captcha",$now,403);
$currency = target($db,"deluxe","currency","Синтетический товар другая валюта");
\Lavka\competitors_record_attempt($db,$currency["id"],observation([offer("100 г","2",100,"USD")]),null,$now,200);
$missing = target($db,"deluxe","missing","Синтетический товар неизвестная цена");
\Lavka\competitors_record_attempt($db,$missing["id"],observation([offer("100 г",null)]),null,$now,200);
$terms = target($db,"deluxe","terms","Синтетические условия доставки","terms");
$termsObs=["kind"=>"terms","title"=>"Условия","offers"=>[],"text"=>"Минимальный заказ 100 грн. Доставка от 100 грн.","links"=>[],"warnings"=>[],"method"=>"synthetic"];
\Lavka\competitors_record_attempt($db,$terms["id"],$termsObs,null,$prior,200);
$termsObs["text"]="Минимальный заказ 200 грн. Доставка от 100 грн.";
\Lavka\competitors_record_attempt($db,$terms["id"],$termsObs,null,$now,200);
\Lavka\competitors_record_attempt($db,$terms["id"],null,"blocked",$now,403);
$catalog = target($db,"deluxe","catalog","Синтетическая страница новинок","catalog");
$catalogObs=["kind"=>"catalog","title"=>"Каталог","offers"=>[],"text"=>"",
    "links"=>[["url"=>"https://deluxe.com.ua/synthetic-old-product","title"=>"Синтетический прежний товар"]],
    "warnings"=>[],"method"=>"synthetic"];
\Lavka\competitors_record_attempt($db,$catalog["id"],$catalogObs,null,$prior,200);
$catalogObs["links"][]=["url"=>"https://deluxe.com.ua/synthetic-new-product","title"=>"Синтетический новый товар"];
\Lavka\competitors_record_attempt($db,$catalog["id"],$catalogObs,null,$now,200);
\Lavka\competitors_record_attempt($db,$catalog["id"],null,"catalog_unrecognized",$now,200);
$archivedError = target($db,"deluxe","archived-error","Архивная ошибка");
\Lavka\competitors_record_attempt($db,$archivedError["id"],null,"not_found",$now,404);
\Lavka\competitors_save_target($db,[
    "source_id"=>"deluxe","kind"=>"product","url"=>$archivedError["url"],"label"=>$archivedError["label"],
    "match_group"=>"synthetic-coriander","match_confirmed"=>true,"enabled"=>false
],$archivedError["id"]);
echo json_encode(["own"=>$own["id"],"valid"=>$valid["id"],"unknown"=>$unknown["id"],"stale"=>$old["id"],
    "error"=>$error["id"],"currency"=>$currency["id"],"missing"=>$missing["id"],"terms"=>$terms["id"],
    "catalog"=>$catalog["id"],"archived_error"=>$archivedError["id"]]);
'''

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
    seed = subprocess.run(docker + ["-i", IMAGE, "php", "-r", SEED],
                          input=json.dumps({"password": password}).encode(), capture_output=True, check=True)
    ids = json.loads(seed.stdout)
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    server = subprocess.Popen(docker + ["--name", name, "-p", f"127.0.0.1:{port}:8000",
                              IMAGE, "php", "-S", "0.0.0.0:8000", "-t", "php/public", "php/public/index.php"],
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
            raise RuntimeError("Competitors synthetic server did not start")
        assert httpx.get(base + "/index.php?route=/api/competitors", trust_env=False).status_code == 401
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(executable_path="/usr/bin/chromium", args=["--no-sandbox"])
            page = browser.new_page(viewport={"width": 1440, "height": 1000}, has_touch=True)
            errors, external = [], []
            page.on("pageerror", lambda error: errors.append(str(error)))
            page.on("request", lambda request: external.append(request.url) if
                    request.url.startswith(("http:", "https:")) and not request.url.startswith(origin + "/") else None)
            page.goto(base + "/")
            page.fill("#password", password)
            page.click("button[type=submit]")
            page.wait_for_selector(".customer-table")
            order_amount = page.evaluate("state.report.metrics.amount")
            page.click("[data-view=competitors]")
            page.wait_for_selector(".competitor-matrix")
            page.click("#competitor-tab-links")
            page.wait_for_selector(".competitor-product-card")
            assert page.locator(".report-controls").is_hidden()
            assert page.locator("#metrics").is_hidden()
            assert page.locator("#yearly-summary").is_hidden()
            assert page.locator("#privacy").is_hidden()
            page.fill("#competitor-search", "Синтетический")
            assert page.locator(".competitor-product-card").count() == 7
            valid = page.locator(".competitor-product-card").filter(
                has=page.locator("h3", has_text="Синтетический кориандр конкурент"))
            assert "Ниже нашей на 20%" in valid.inner_text()
            assert valid.locator(".competitor-price-tag").count() == 1
            assert page.locator(".competitor-difference").count() == 1
            assert page.locator(".competitor-error").count() == 1
            missing = page.locator(".competitor-product-card").filter(
                has=page.locator("h3", has_text="Синтетический товар неизвестная цена"))
            assert "Цена неизвестна" in missing.inner_text()
            assert "0 UAH" not in missing.inner_text()

            # The error tile opens every active failed page, even when current
            # product filters hide its source or kind. Native button activation
            # must work from the keyboard as well as on a touch screen.
            page.select_option("#competitor-source", "lavka")
            page.fill("#competitor-search", "Фильтр скрывает все карточки")
            assert page.locator(".competitor-product-card").count() == 0
            attention = page.locator("[data-comp-action=show-errors]")
            assert attention.count() == 1
            assert attention.evaluate("el=>el.tagName") == "BUTTON"
            attention.focus()
            page.keyboard.press("Enter")
            assert page.locator("#competitor-tab-errors").get_attribute("aria-selected") == "true"
            error_cards = page.locator(".competitor-errors-list .competitor-error-card")
            assert error_cards.count() == 3
            assert page.evaluate("competitorState.source") == "all"
            assert page.evaluate("competitorState.search") == ""
            error_text = page.locator("#competitor-tabpanel").inner_text()
            for label in ["Синтетический товар ошибка сайта", "Синтетические условия доставки",
                          "Синтетическая страница новинок", "Карточка товара",
                          "Доставка и скидки", "Страница ассортимента", "HTTP 403"]:
                assert label in error_text, label
            assert "Архивная ошибка" not in error_text
            assert "Синтетический товар устаревшая цена" not in error_text
            assert "Сайт запросил CAPTCHA" in error_text
            assert "Ссылки на товары не распознаны" in error_text
            assert error_cards.locator("a[href^='https://deluxe.com.ua/']").count() >= 3
            terms_error = error_cards.filter(has_text="Синтетические условия доставки")
            assert terms_error.locator("[data-comp-action=history]").count() == 1
            assert terms_error.locator("[data-comp-action=queue]").count() == 1
            terms_error.locator("[data-comp-action=history]").click()
            page.wait_for_selector(".competitor-attempt-list")
            assert "HTTP 403" in page.locator(".competitor-attempt-list").inner_text()
            assert page.locator(".competitor-history-snapshot").count() == 2
            page.click("[data-action=close-dialog]")
            catalog_error = error_cards.filter(has_text="Синтетическая страница новинок")
            catalog_error.locator("[data-comp-action=queue]").click()
            page.wait_for_function("() => !competitorState.queueing")
            assert page.evaluate("(id)=>competitorState.data.targets.find(t=>t.id===id).queued", ids["catalog"])
            assert error_cards.count() == 3  # A queued retry is still an unresolved error.
            for width in [390, 320]:
                page.set_viewport_size({"width": width, "height": 844})
                assert page.evaluate("document.documentElement.scrollWidth<=innerWidth")
                assert error_cards.first.locator("[data-comp-action=history]").bounding_box()["height"] >= 44
                assert error_cards.first.locator("[data-comp-action=queue]").bounding_box()["height"] >= 44
            page.set_viewport_size({"width": 390, "height": 844})
            page.screenshot(path=str(ARTIFACTS / "competitors-errors-iphone13.png"), full_page=True)

            # Clearing the last errors produces an explicit empty state. Refresh
            # changes only the controlled response; successful page history stays
            # intact in the synthetic database for the remaining checks.
            saved_overview = page.request.get(base + "/index.php?route=/api/competitors").json()
            clean_overview = deepcopy(saved_overview)
            for target_row in clean_overview["targets"]:
                if (target_row.get("last_attempt") or {}).get("state") == "error":
                    target_row["last_attempt"] = {"state": "ok", "error_code": None, "http_status": 200,
                                                  "checked_at": target_row["last_attempt"]["checked_at"]}
            overview_pattern = "**/index.php?route=%2Fapi%2Fcompetitors"
            page.route(overview_pattern, lambda route: route.fulfill(
                status=200, content_type="application/json", body=json.dumps(clean_overview)))
            page.click("[data-comp-action=refresh]")
            page.wait_for_function("() => !competitorState.loading")
            assert page.locator(".competitor-error-card").count() == 0
            assert page.locator("#competitor-tabpanel .competitor-empty").is_visible()
            page.unroute(overview_pattern)
            page.click("[data-comp-action=refresh]")
            page.wait_for_function("() => !competitorState.loading")
            assert error_cards.count() == 3
            page.click("#competitor-tab-links")
            page.fill("#competitor-search", "Синтетический")
            page.set_viewport_size({"width": 1440, "height": 1000})

            # Keyboard tab navigation and stable display of all history types.
            page.click("#competitor-tab-links")
            page.keyboard.press("ArrowRight")
            assert page.locator("#competitor-tab-changes").get_attribute("aria-selected") == "true"
            assert page.locator(".competitor-changes article").count() >= 2
            page.click("#competitor-tab-terms")
            assert "Синтетические условия доставки" in page.locator("#competitor-tabpanel").inner_text()
            page.locator(".competitor-watcher").filter(has_text="Синтетические условия").locator("summary").click()
            assert "Минимальный заказ 200 грн." in page.locator("#competitor-tabpanel").inner_text()
            page.click("#competitor-tab-discoveries")
            assert page.locator(".competitor-discovery-list article").count() == 1
            assert "Синтетический новый товар" in page.locator(".competitor-discovery-list").inner_text()
            page.click("#competitor-tab-links")
            valid = page.locator(".competitor-product-card").filter(
                has=page.locator("h3", has_text="Синтетический кориандр конкурент"))
            valid.locator("[data-comp-action=history]").click()
            page.wait_for_selector(".competitor-history-snapshot")
            assert page.locator(".competitor-history-snapshot").count() == 2
            page.click("[data-action=close-dialog]")

            # Forms preserve edits during result refreshes and section navigation.
            page.click("[data-comp-action=add][data-kind=product]")
            form = page.locator("#competitor-target-form")
            form.locator("[name=source_id]").select_option("deluxe")
            form.locator("[name=url]").fill("https://deluxe.com.ua/synthetic-added-by-ui")
            form.locator("[name=label]").fill("Синтетическая новая карточка с длинным названием для проверки переноса")
            form.locator("[name=match_group]").fill("synthetic-coriander")
            form.locator("[name=grams_override]").fill("100")
            page.click("[data-comp-action=refresh]")
            page.wait_for_function("() => !competitorState.loading")
            assert form.locator("[name=url]").input_value().endswith("synthetic-added-by-ui")
            page.click("[data-view=customers]")
            assert page.locator(".report-controls").is_visible()
            assert page.locator("#metrics").is_visible()
            assert page.evaluate("state.report.metrics.amount") == order_amount
            page.click("[data-view=competitors]")
            assert form.locator("[name=label]").input_value().startswith("Синтетическая новая")

            for width in [390, 320, 1440]:
                page.set_viewport_size({"width": width, "height": 844})
                assert page.evaluate("document.documentElement.scrollWidth<=innerWidth")
                assert form.locator("[name=url]").bounding_box()["width"] > 100
                if width <= 390:
                    assert form.locator("[name=url]").evaluate("el=>parseFloat(getComputedStyle(el).fontSize)") >= 16
                    assert form.locator("[type=submit]").bounding_box()["height"] >= 44
            page.set_viewport_size({"width": 390, "height": 844})
            page.screenshot(path=str(ARTIFACTS / "competitors-form-iphone13.png"), full_page=True)
            form.locator("[type=submit]").click()
            page.wait_for_function("() => !competitorState.saving && !competitorState.formOpen")
            added = page.evaluate("competitorState.data.targets.find(t=>t.url.endsWith('synthetic-added-by-ui'))")
            assert added and not added["match_confirmed"] and added["queued"]
            page.fill("#competitor-search", "Синтетическая новая")
            card = page.locator(".competitor-product-card")
            assert card.count() == 1
            card.locator("[data-comp-action=edit]").click()
            form.locator("[name=match_confirmed]").check()
            form.locator("[type=submit]").click()
            page.wait_for_function("() => !competitorState.saving && !competitorState.formOpen")
            assert page.evaluate("competitorState.data.targets.find(t=>t.url.endsWith('synthetic-added-by-ui')).match_confirmed")
            card.locator("[data-comp-action=edit]").click()
            form.locator("[data-comp-action=archive]").click()
            page.wait_for_function("() => !competitorState.saving && !competitorState.formOpen")
            assert card.count() == 0
            page.check("#competitor-archived")
            assert card.count() == 1
            assert "В архиве" in card.inner_text()
            card.locator("[data-comp-action=edit]").click()
            form.locator("[data-comp-action=archive]").click()
            page.wait_for_function("() => !competitorState.saving && !competitorState.formOpen")
            assert "Ожидает первой проверки" in card.inner_text()
            card.locator("[data-comp-action=queue]").click()
            page.wait_for_function("() => !competitorState.queueing")
            assert "В очереди" in card.inner_text()
            page.fill("#competitor-search", "Синтетический")
            assert page.evaluate("document.documentElement.scrollWidth<=innerWidth")
            page.screenshot(path=str(ARTIFACTS / "competitors-products-iphone13.png"), full_page=True)
            page.set_viewport_size({"width": 1440, "height": 1000})
            page.screenshot(path=str(ARTIFACTS / "competitors-products-desktop.png"), full_page=True)

            # A slow competitor response must not replace a subsequently opened customer.
            history_data = page.request.get(base + "/index.php?route=/api/competitors/targets/" + str(ids["valid"])).json()
            pending = []
            history_pattern = "**/index.php?route=%2Fapi%2Fcompetitors%2Ftargets%2F" + str(ids["valid"])
            page.route(history_pattern, lambda route: pending.append(route))
            valid = page.locator(".competitor-product-card").filter(
                has=page.locator("h3", has_text="Синтетический кориандр конкурент"))
            valid.locator("[data-comp-action=history]").click()
            page.wait_for_selector("#drawer[open]")
            page.click("[data-action=close-dialog]")
            page.click("[data-view=customers]")
            page.locator("[data-action=customer]").first.click()
            page.wait_for_selector("#drawer .order-detail")
            assert len(pending) == 1
            pending[0].fulfill(status=200, content_type="application/json", body=json.dumps(history_data))
            page.wait_for_load_state("networkidle")
            assert page.locator("#drawer .order-detail").count() > 0
            assert page.locator("#drawer .competitor-history").count() == 0
            page.unroute(history_pattern)
            page.click("[data-action=close-dialog]")
            page.click("[data-view=competitors]")

            # An unavailable saved-data API leaves successful observations on screen.
            route_pattern = "**/index.php?route=%2Fapi%2Fcompetitors"
            page.route(route_pattern, lambda route: route.fulfill(
                status=503, content_type="application/json", body='{"detail":"Синтетическая ошибка API"}'))
            page.click("[data-comp-action=refresh]")
            page.wait_for_function("() => !competitorState.loading")
            assert "Синтетическая ошибка API" in page.locator(".competitors-module .notice").inner_text()
            assert page.locator(".competitor-product-card").count() == 7
            page.unroute(route_pattern)
            assert not errors, errors
            assert not external, external
            browser.close()
        print(json.dumps({"php_image": IMAGE, "mobile_390_and_320": "passed",
                          "crud_archive_queue": "passed", "comparison_exclusions": "passed",
                          "history_terms_discoveries": "passed", "draft_and_order_preservation": "passed",
                          "clickable_attention_and_all_page_errors": "passed",
                          "api_error_preservation_and_no_external_requests": "passed"}))
    finally:
        subprocess.run(["docker", "rm", "-f", name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        server.wait(timeout=15)
