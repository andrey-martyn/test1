"""Price-matrix browser checks on synthetic pages and orders only."""
from copy import deepcopy
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timedelta, timezone
from urllib.parse import parse_qs, urlsplit

import httpx
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT), str(ROOT / "tests")]
from test_production import snapshot
from sync_mysql import apply_snapshot

IMAGE = os.environ.get("ANALYTICS_PHP_IMAGE", "lavka-php73-dev")
ARTIFACTS = Path(os.environ.get("ANALYTICS_TEST_OUTPUT", "/workspace/work/competitors/matrix-browser"))
ARTIFACTS.mkdir(parents=True, exist_ok=True)
name = "lavka-matrix-browser-" + secrets.token_hex(5)

SEED = r'''
require "php/src/Auth.php";
require "php/src/Competitors.php";
$input=json_decode(stream_get_contents(STDIN),true);
\Lavka\Auth::setPassword("/test-data",$input["password"]);
$db=\Lavka\competitors_db("/test-data");
$hosts=["lavka"=>"lavka-speciy.com","deluxe"=>"deluxe.com.ua","banka"=>"banka-speciy.in.ua",
    "spicery"=>"spiceryshop.com.ua","specii"=>"www.specii.in.ua"];
function target($db,$source,$suffix,$label,$group,$confirmed=true,$enabled=true) {
    global $hosts;
    static $ownProduct=900000;
    $url=$source==="lavka"?"https://lavka-speciy.com/index.php?route=product/product&product_id=".(++$ownProduct):
        "https://".$hosts[$source]."/synthetic-matrix-".$suffix;
    return \Lavka\competitors_save_target($db,[
        "source_id"=>$source,"kind"=>"product","url"=>$url,
        "label"=>$label,"match_group"=>$group,"match_confirmed"=>$confirmed,"enabled"=>$enabled,
        "pack_label"=>$source==="lavka"?"50 г":""
    ]);
}
function offer($price,$grams=50,$currency="UAH",$unit="pack",$condition="",$stock="in_stock",$minimum=1) {
    return ["key"=>(string)$grams."-".$unit,"label"=>($grams===null?"Вес не указан":$grams." г"),
        "price"=>$price,"old_price"=>null,"currency"=>$currency,"grams"=>$grams,"unit"=>$unit,
        "condition"=>$condition,"availability"=>$stock,"min_quantity"=>$minimum];
}
function record($db,$target,$offers,$at) {
    foreach($offers as &$value) {
        $value["packaging"]=$target["source_id"]==="spicery"?"doypack":($target["source_id"]==="specii"?"kraft":"unknown");
    }
    unset($value);
    \Lavka\competitors_record_attempt($db,$target["id"],[
        "kind"=>"product","title"=>$target["label"],"offers"=>$offers,"text"=>"","links"=>[],
        "warnings"=>[],"method"=>"synthetic"
    ],null,$at,200);
}
$now=gmdate("Y-m-d\TH:i:s\Z");
$old=gmdate("Y-m-d\TH:i:s\Z",time()-72*3600);
$ids=[];
$ids["main_own"]=target($db,"lavka","main-own","Матрица А — кориандр Лавки","matrix-main",false);
record($db,$ids["main_own"],[offer("100",50),offer("300",200)],$now);
$ids["main_deluxe"]=target($db,"deluxe","main-deluxe","Матрица А — кориандр Deluxe","matrix-main");
record($db,$ids["main_deluxe"],[offer("90",50),offer("260",200)],$now);
$ids["main_banka"]=target($db,"banka","main-banka","Матрица А — кориандр Банки","matrix-main");
record($db,$ids["main_banka"],[offer("90",50),offer("250",200)],$now);
$invalid=[
    ["deluxe","stale","Матрица А — устаревшее предложение",[offer("1")],$old,true],
    ["banka","unconfirmed","Матрица А — непроверенное соответствие",[offer("1")],$now,false],
    ["spicery","conditional","Матрица А — цена по условию",[offer("1",50,"UAH","pack","От суммы заказа 2000 грн.")],$now,true],
    ["spicery","unknown","Матрица А — неизвестный вес",[offer("1",null)],$now,true],
    ["spicery","unitkg","Матрица А — цена за килограмм",[offer("1",1000,"UAH","kg")],$now,true],
    ["spicery","minqty","Матрица А — оптовое количество",[offer("1",50,"UAH","pack","","in_stock",5)],$now,true],
    ["specii","currency","Матрица А — другая валюта",[offer("1",50,"USD")],$now,true],
    ["specii","outstock","Матрица А — нет в наличии",[offer("1",50,"UAH","pack","","out_of_stock")],$now,true],
    ["specii","unknownstock","Матрица А — наличие неизвестно",[offer("1",50,"UAH","pack","","unknown")],$now,true],
];
foreach($invalid as $row) {
    $t=target($db,$row[0],$row[1],$row[2],"matrix-main",$row[5]);
    record($db,$t,$row[3],$row[4]);
    $ids[$row[1]]=$t;
}
$ids["failed"]=target($db,"specii","failed","Матрица А — ошибка источника","matrix-main");
record($db,$ids["failed"],[offer("1")],$now);
\Lavka\competitors_record_attempt($db,$ids["failed"]["id"],null,"captcha",$now,403);
$ids["cheap_own"]=target($db,"lavka","cheap-own","Матрица Б — наша цена уже ниже","matrix-own-cheap",false);
record($db,$ids["cheap_own"],[offer("70")],$now);
$ids["cheap_deluxe"]=target($db,"deluxe","cheap-deluxe","Матрица Б — предложение конкурента","matrix-own-cheap");
record($db,$ids["cheap_deluxe"],[offer("100")],$now);
$ids["confirm_own"]=target($db,"lavka","confirm-own","Матрица В — проверка соответствия","matrix-confirm",false);
record($db,$ids["confirm_own"],[offer("80")],$now);
$ids["confirm_spicery"]=target($db,"spicery","confirm-spicery","Матрица В — неподтверждённый аналог","matrix-confirm",false);
record($db,$ids["confirm_spicery"],[offer("70")],$now);
$ids["tie_own"]=target($db,"lavka","tie-own","Матрица Г — равные цены","matrix-tie",false);
record($db,$ids["tie_own"],[offer("90")],$now);
$ids["tie_deluxe"]=target($db,"deluxe","tie-deluxe","Матрица Г — равная цена конкурента","matrix-tie");
record($db,$ids["tie_deluxe"],[offer("90")],$now);
$ids["none_own"]=target($db,"lavka","none-own","Матрица Д — нет сопоставимой цены","matrix-none",false);
record($db,$ids["none_own"],[offer("60")],$now);
$ids["none_other"]=target($db,"banka","none-banka","Матрица Д — фасовка 200 г","matrix-none");
record($db,$ids["none_other"],[offer("30",200)],$now);
$ids["near_own"]=target($db,"lavka","near-own","Матрица Е — нужен полный отступ два процента","matrix-near",false);
record($db,$ids["near_own"],[offer("99")],$now);
$ids["near_other"]=target($db,"banka","near-banka","Матрица Е — базовая цена конкурента","matrix-near");
record($db,$ids["near_other"],[offer("100")],$now);
$ids["dup_a"]=target($db,"lavka","dup-a","Матрица Ж — отдельный товар первый","matrix-duplicate",false);
record($db,$ids["dup_a"],[offer("120")],$now);
$ids["dup_b"]=target($db,"lavka","dup-b","Матрица Ж — отдельный товар второй","matrix-duplicate",false);
record($db,$ids["dup_b"],[offer("80")],$now);
$ids["dup_other"]=target($db,"deluxe","dup-other","Матрица Ж — общий кандидат","matrix-duplicate");
record($db,$ids["dup_other"],[offer("100")],$now);
$ids["seo_own"]=\Lavka\competitors_save_target($db,[
    "source_id"=>"lavka","kind"=>"product","url"=>"https://lavka-speciy.com/synthetic-matrix-seo-own",
    "label"=>"Матрица З — собственная SEO-ссылка без ID","match_group"=>"matrix-seo",
    "match_confirmed"=>false,"enabled"=>true,"pack_label"=>"50 г"
]);
record($db,$ids["seo_own"],[offer("77")],$now);
$ids["seo_other"]=target($db,"deluxe","seo-other","Матрица З — конкурент SEO-товара","matrix-seo");
record($db,$ids["seo_other"],[offer("100")],$now);
$out=[];foreach($ids as $key=>$target)$out[$key]=$target["id"];echo json_encode($out);
'''


def matrix_row(page, group, grams=50):
    return page.locator(f".competitor-matrix tbody tr[data-matrix-row='{group}|{grams}']")


def price(cell):
    label = cell.locator(".competitor-cell-price")
    if not label.count():
        return None
    match = re.search(r"[\d]+(?:[\s\u00a0\u202f][\d]{3})*(?:[.,][\d]+)?", label.first.inner_text())
    assert match, label.first.inner_text()
    return float(re.sub(r"[\s\u00a0\u202f]", "", match.group(0)).replace(",", "."))


def observation_digest(directory):
    with sqlite3.connect(directory / "competitors.sqlite3") as db:
        values = db.execute("SELECT id,observation FROM competitor_targets ORDER BY id").fetchall()
    return hashlib.sha256(json.dumps(values).encode()).hexdigest()


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
    result = subprocess.run(docker + ["-i", IMAGE, "php", "-r", SEED],
                            input=json.dumps({"password": password}).encode(), capture_output=True, check=True)
    ids = json.loads(result.stdout)
    before_prices = observation_digest(data)
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
            raise RuntimeError("Synthetic matrix server did not start")
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(executable_path="/usr/bin/chromium", args=["--no-sandbox"])
            page = browser.new_page(viewport={"width": 1440, "height": 1000}, has_touch=True)
            errors, external, mutations = [], [], []
            page.on("pageerror", lambda error: errors.append(str(error)))
            page.on("request", lambda request: external.append(request.url) if
                    request.url.startswith(("http:", "https:")) and not request.url.startswith(origin + "/") else None)
            page.goto(base + "/")
            page.fill("#password", password)
            page.click("button[type=submit]")
            page.click("[data-view=customers]")
            page.wait_for_selector(".customer-table")
            order_amount = page.evaluate("state.report.metrics.amount")
            page.on("request", lambda request: mutations.append(
                {"url": request.url, "method": request.method, "body": request.post_data}) if
                request.method in ["POST", "PUT", "PATCH", "DELETE"] else None)
            page.click("[data-view=competitors]")
            page.wait_for_selector(".competitor-matrix")
            assert page.locator("#competitor-tab-products").get_attribute("aria-selected") == "true"
            assert page.locator(".competitor-product-card").count() == 0
            page.fill("#competitor-search", "Матрица")
            assert page.locator("#competitor-matrix-pack").input_value() == "500"
            page.select_option("#competitor-matrix-pack", "50")

            main = matrix_row(page, "matrix-main")
            assert main.count() == 1
            assert price(main.locator("td[data-source=lavka]")) == 100
            assert price(main.locator("td[data-source=deluxe]")) == 90
            assert price(main.locator("td[data-source=banka]")) == 90
            assert price(main.locator("td[data-source=spicery]")) is None
            assert main.locator("td[data-source=specii]").evaluate("el=>el.classList.contains('not-comparable')")
            assert not main.locator("td[data-source=specii]").evaluate("el=>el.classList.contains('is-best')")
            assert main.locator("td[data-source=deluxe]").evaluate("el=>el.classList.contains('is-best')")
            assert main.locator("td[data-source=banka]").evaluate("el=>el.classList.contains('is-best')")
            assert not main.locator("td[data-source=lavka]").evaluate("el=>el.classList.contains('is-best')")
            tie = matrix_row(page, "matrix-tie")
            assert tie.locator("td[data-source=lavka]").evaluate("el=>el.classList.contains('is-best')")
            assert tie.locator("td[data-source=deluxe]").evaluate("el=>el.classList.contains('is-best')")
            assert matrix_row(page, "matrix-main", 200).count() == 0
            assert price(matrix_row(page, "matrix-none").locator("td[data-source=banka]")) is None
            duplicate_a = matrix_row(page, "matrix-duplicate@" + str(ids["dup_a"]))
            duplicate_b = matrix_row(page, "matrix-duplicate@" + str(ids["dup_b"]))
            assert page.locator(".competitor-matrix tbody tr[data-matrix-row^='matrix-duplicate@']").count() == 2
            assert price(duplicate_a.locator("td[data-source=lavka]")) == 120
            assert price(duplicate_b.locator("td[data-source=lavka]")) == 80
            assert duplicate_a.locator("td[data-source=deluxe]").evaluate("el=>el.classList.contains('is-best')")
            assert duplicate_b.locator("td[data-source=lavka]").evaluate("el=>el.classList.contains('is-best')")
            assert price(matrix_row(page, "matrix-seo").locator("td[data-source=lavka]")) == 77

            # Other actual packs get their own rows; a 200g price is never
            # divided into a fictitious 50g offer in the primary comparison.
            page.select_option("#competitor-matrix-pack", "all")
            assert price(matrix_row(page, "matrix-main", 200).locator("td[data-source=lavka]")) == 300
            assert price(matrix_row(page, "matrix-main", 200).locator("td[data-source=deluxe]")) == 260
            assert price(matrix_row(page, "matrix-main", 200).locator("td[data-source=banka]")) == 250
            assert matrix_row(page, "matrix-main", 200).locator("td[data-source=banka]").evaluate("el=>el.classList.contains('is-best')")
            page.select_option("#competitor-matrix-pack", "minimum")
            assert matrix_row(page, "matrix-main").count() == 1
            assert matrix_row(page, "matrix-main", 200).count() == 0
            page.select_option("#competitor-matrix-pack", "50")

            # Read-only drafts use the cheapest eligible competitor, then cap
            # the result at our own current price so there are no price rises.
            main.locator("[data-matrix-select]").check()
            cheap = matrix_row(page, "matrix-own-cheap")
            cheap.locator("[data-matrix-select]").check()
            near = matrix_row(page, "matrix-near")
            near.locator("[data-matrix-select]").check()
            duplicate_a.locator("[data-matrix-select]").check()
            duplicate_b.locator("[data-matrix-select]").check()
            page.click("[data-comp-action=matrix-preview]")
            assert float(main.locator(".competitor-suggestion").get_attribute("data-price")) == 88.20
            assert float(cheap.locator(".competitor-suggestion").get_attribute("data-price")) == 70
            assert float(near.locator(".competitor-suggestion").get_attribute("data-price")) == 98
            assert float(duplicate_a.locator(".competitor-suggestion").get_attribute("data-price")) == 98
            assert float(duplicate_b.locator(".competitor-suggestion").get_attribute("data-price")) == 80
            assert matrix_row(page, "matrix-tie").locator(".competitor-suggestion").count() == 0
            page.fill("#competitor-batch-cut", "5")
            page.click("[data-comp-action=matrix-preview]")
            assert float(main.locator(".competitor-suggestion").get_attribute("data-price")) == 85.50
            assert float(cheap.locator(".competitor-suggestion").get_attribute("data-price")) == 70
            main.locator("[data-matrix-cut]").fill("3")
            page.click("[data-comp-action=matrix-preview]")
            assert float(main.locator(".competitor-suggestion").get_attribute("data-price")) == 87.30
            assert not mutations, mutations
            assert observation_digest(data) == before_prices

            # Once a successful own-catalog snapshot exists, its product/pack
            # price is authoritative. Missing DB products never silently take
            # a legacy public-page price. Cached errors stay visible but cannot
            # lead the matrix or produce a price draft.
            real_overview = page.request.get(base + "/index.php?route=/api/competitors").json()
            catalog_products = []
            for target_row in real_overview["targets"]:
                if target_row["source_id"] != "lavka" or not target_row["label"].startswith("Матрица"):
                    continue
                if target_row["id"] == ids["none_own"]:
                    continue
                query_product = parse_qs(urlsplit(target_row["url"]).query).get("product_id")
                if not query_product:  # A genuinely unmapped SEO URL is outside this snapshot.
                    continue
                product_id = int(query_product[0])
                offers = deepcopy(target_row["observation"]["offers"])
                if target_row["id"] == ids["main_own"]:
                    for item in offers:
                        item["price"] = "105" if item["grams"] == 50 else "320"
                catalog_products.append({"product_id": product_id, "source_product_id": product_id,
                                         "target_id": target_row["id"], "label": target_row["label"],
                                         "url": target_row["url"], "source": "mysql_catalog",
                                         "checked_at": real_overview["server_now"], "offers": offers,
                                         "warnings": []})
            overview_pattern = "**/index.php?route=%2Fapi%2Fcompetitors"

            def show_catalog(state, last_success, error_code=None):
                overview = deepcopy(real_overview)
                overview["own_catalog"] = {"state": state, "last_success": last_success,
                                            "checked_at": overview["server_now"],
                                            "error_code": error_code, "products": catalog_products}
                if state == "ok":
                    # A superseded legacy HTTP error cannot conceal a fresh DB price.
                    for target_row in overview["targets"]:
                        if target_row["id"] == ids["main_own"]:
                            target_row["last_attempt"] = {"state": "error", "error_code": "price_unknown",
                                                          "http_status": 200, "checked_at": overview["server_now"]}
                page.route(overview_pattern, lambda route: route.fulfill(
                    status=200, content_type="application/json", body=json.dumps(overview)))
                page.click("[data-comp-action=refresh]")
                page.wait_for_function("() => !competitorState.loading")
                page.unroute(overview_pattern)

            page.click("[data-comp-action=matrix-clear]")
            show_catalog("ok", real_overview["server_now"])
            assert price(matrix_row(page, "matrix-main").locator("td[data-source=lavka]")) == 105
            assert "База магазина" in matrix_row(page, "matrix-main").locator("td[data-source=lavka]").inner_text()
            missing_own = matrix_row(page, "matrix-none").locator("td[data-source=lavka]")
            assert price(missing_own) is None
            assert "отсутствует в снимке базы" in missing_own.inner_text()
            assert matrix_row(page, "matrix-none").locator("[data-matrix-select]").is_disabled()
            seo_cell = matrix_row(page, "matrix-seo").locator("td[data-source=lavka]")
            assert price(seo_cell) == 77
            assert "Сайт" in seo_cell.inner_text()
            page.click("#competitor-tab-errors")
            assert page.locator(".competitor-error-card").count() == 1
            assert "Сайт запросил CAPTCHA" in page.locator("#competitor-tabpanel").inner_text()
            page.click("#competitor-tab-products")
            page.fill("#competitor-search", "Матрица")
            seo_target = next(t for t in real_overview["targets"] if t["id"] == ids["seo_own"])
            seo_product = {"product_id": 999001, "source_product_id": 999001, "target_id": ids["seo_own"],
                           "label": seo_target["label"], "url": seo_target["url"], "source": "mysql_catalog",
                           "checked_at": real_overview["server_now"],
                           "offers": [{**seo_target["observation"]["offers"][0], "price": "75"}], "warnings": []}
            catalog_products.append(seo_product)
            show_catalog("ok", real_overview["server_now"])
            assert price(matrix_row(page, "matrix-seo").locator("td[data-source=lavka]")) == 75
            assert "База магазина" in matrix_row(page, "matrix-seo").locator("td[data-source=lavka]").inner_text()
            catalog_products.pop()
            old_success = (datetime.now(timezone.utc) - timedelta(hours=72)).strftime("%Y-%m-%dT%H:%M:%SZ")
            for state_name, success, error_code in [("stale", old_success, None),
                                                    ("error", real_overview["server_now"], "catalog_sync_failed")]:
                show_catalog(state_name, success, error_code)
                own_cell = matrix_row(page, "matrix-main").locator("td[data-source=lavka]")
                assert price(own_cell) == 105  # Last successful DB price, not legacy100 or zero.
                assert own_cell.evaluate("el=>el.classList.contains('not-comparable')")
                assert not own_cell.evaluate("el=>el.classList.contains('is-best')")
                assert matrix_row(page, "matrix-main").locator("[data-matrix-select]").is_disabled()
                assert price(matrix_row(page, "matrix-none").locator("td[data-source=lavka]")) is None
                assert price(matrix_row(page, "matrix-seo").locator("td[data-source=lavka]")) == 77
            page.click("[data-comp-action=refresh]")
            page.wait_for_function("() => !competitorState.loading")
            assert price(matrix_row(page, "matrix-main").locator("td[data-source=lavka]")) == 100
            assert observation_digest(data) == before_prices

            # A detail drawer exposes evidence without fetching a competitor
            # in the user's browser or turning conditional prices into minima.
            main.locator("td[data-source=deluxe] [data-comp-action=matrix-detail]").click()
            page.wait_for_selector("#drawer[open]")
            assert page.locator("#drawer-content a[href='https://deluxe.com.ua/synthetic-matrix-main-deluxe']").count() >= 1
            assert "50 г" in page.locator("#drawer-content").inner_text()
            assert "200 г" in page.locator("#drawer-content").inner_text()
            page.click("[data-action=close-dialog]")

            for width in [390, 320, 1440]:
                page.set_viewport_size({"width": width, "height": 844})
                assert page.evaluate("document.documentElement.scrollWidth<=innerWidth")
                scroll = page.locator(".competitor-matrix-scroll")
                row_heading = main.locator("th[scope=row]")
                heading_styles = row_heading.evaluate("el=>({top:getComputedStyle(el).top,whiteSpace:getComputedStyle(el).whiteSpace})")
                assert heading_styles == {"top": "auto", "whiteSpace": "normal"}, heading_styles
                if width <= 390:
                    assert scroll.evaluate("el=>el.scrollWidth>el.clientWidth")
                    assert main.locator("[data-matrix-select]").locator("..").bounding_box()["height"] >= 44
            page.set_viewport_size({"width": 390, "height": 844})
            page.screenshot(path=str(ARTIFACTS / "matrix-iphone13.png"), full_page=True)
            scroll.evaluate("el=>el.scrollIntoView({block:'start'})")
            pack_geometry = scroll.evaluate("""el=>Array.from(el.querySelectorAll('tbody tr')).map(row=>{
                const cell=row.querySelector('td.competitor-matrix-pack'),a=row.getBoundingClientRect(),b=cell.getBoundingClientRect();
                return {label:cell.innerText,top:b.top-row.getBoundingClientRect().top,height:b.height-a.height,cssTop:getComputedStyle(cell).top};
            })""")
            assert all(item["label"] == "50 г" and abs(item["top"]) <= 1 and abs(item["height"]) <= 1
                       and item["cssTop"] == "auto" for item in pack_geometry), pack_geometry
            visible_packs = scroll.evaluate("""el=>{
                const r=el.getBoundingClientRect(),header=el.querySelector('thead').getBoundingClientRect();
                return Array.from(el.querySelectorAll('tbody td.competitor-matrix-pack')).map(cell=>{
                    const b=cell.getBoundingClientRect(),top=Math.max(b.top,header.bottom),bottom=Math.min(b.bottom,r.bottom,innerHeight);
                    if(bottom-top<12)return null;
                    const at=document.elementFromPoint(b.left+10,top+6);
                    return {label:cell.innerText,visible:at?.closest('td.competitor-matrix-pack')===cell};
                }).filter(Boolean);
            }""")
            assert visible_packs and all(item["visible"] for item in visible_packs), visible_packs
            page.screenshot(path=str(ARTIFACTS / "matrix-iphone13-viewport.png"))
            scroll.evaluate("el=>{el.scrollLeft=el.scrollWidth;el.scrollTop=el.scrollHeight}")
            scroll.scroll_into_view_if_needed()
            last_heading = page.locator(".competitor-matrix tbody th[scope=row]").last
            assert abs(last_heading.bounding_box()["x"] - scroll.bounding_box()["x"]) <= 2
            page.screenshot(path=str(ARTIFACTS / "matrix-iphone13-scrolled.png"), full_page=True)
            scroll.evaluate("el=>{el.scrollLeft=0;el.scrollTop=0}")
            page.set_viewport_size({"width": 1440, "height": 1000})
            page.screenshot(path=str(ARTIFACTS / "matrix-desktop.png"), full_page=True)

            # Confirming a candidate is the only intended mutation here. The
            # request updates one comparison link and never contains a price.
            confirm = matrix_row(page, "matrix-confirm")
            assert price(confirm.locator("td[data-source=spicery]")) is None
            confirm.locator("td[data-source=spicery] [data-comp-action=matrix-detail]").click()
            page.wait_for_selector("#drawer[open]")
            confirm_button = page.locator(f"#drawer [data-comp-action=matrix-confirm][data-id='{ids['confirm_spicery']}']")
            assert confirm_button.count() == 1
            confirm_button.click()
            page.wait_for_function("(id)=>competitorState.data.targets.find(t=>t.id===id).match_confirmed",
                                   arg=ids["confirm_spicery"])
            if page.locator("#drawer").evaluate("el=>el.open"):
                page.click("[data-action=close-dialog]")
            assert price(matrix_row(page, "matrix-confirm").locator("td[data-source=spicery]")) == 70
            assert len(mutations) == 1 and mutations[0]["method"] == "PUT", mutations
            assert mutations[0]["url"].endswith("%2Ftargets%2F" + str(ids["confirm_spicery"]))
            body = json.loads(mutations[0]["body"])
            assert body["match_confirmed"] is True and body["match_group"] == "matrix-confirm"
            assert set(body) <= {"source_id", "kind", "url", "label", "enabled", "grams_override",
                                 "pack_label", "match_group", "match_confirmed", "packaging"}
            assert observation_digest(data) == before_prices

            # Errors remain discoverable in their own view; normal navigation
            # back to the matrix preserves actual analytics totals.
            page.click("#competitor-tab-errors")
            assert page.locator(".competitor-error-card").count() == 1
            assert "Сайт запросил CAPTCHA" in page.locator("#competitor-tabpanel").inner_text()
            page.click("#competitor-tab-products")
            page.wait_for_selector(".competitor-matrix")
            page.click("[data-view=customers]")
            assert page.evaluate("state.report.metrics.amount") == order_amount
            assert not errors, errors
            assert not external, external
            browser.close()
        print(json.dumps({"php_image": IMAGE, "exact_packs_50_200": "passed",
                          "eligibility_currency_and_ties": "passed", "batch_and_row_preview": "passed",
                          "own_price_cap_and_no_price_writes": "passed", "explicit_link_confirmation": "passed",
                          "own_db_catalog_and_no_missing_product_fallback": "passed",
                          "unmapped_seo_and_independent_own_rows": "passed",
                          "mobile_and_source_evidence": "passed"}))
    finally:
        subprocess.run(["docker", "rm", "-f", name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        server.wait(timeout=15)
