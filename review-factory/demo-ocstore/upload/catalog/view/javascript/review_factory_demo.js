/* ReviewFactory TEST demo */
(() => {
  "use strict";
  if (location.hostname !== "test.lavka-speciy.com") return;
  const cache = new Map(),
    requested = new Set(),
    detailRequested = new Set();
  let timer,
    running = false,
    needsScan = false;
  const node = (tag, cls, value) => {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    if (value !== undefined) el.textContent = value;
    return el;
  };
  const id = (value) =>
    /^\d{1,10}$/.test(String(value || "")) && Number(value) > 0 && Number(value) <= 2147483647
      ? Number(value)
      : null;
  const score = (value) =>
    Number(value).toLocaleString("ru-RU", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  function countLabel(value, words) {
    const n = Number(value),
      last = n % 10,
      lastTwo = n % 100;
    return (
      n.toLocaleString("ru-RU") +
      " " +
      (last === 1 && lastTwo !== 11
        ? words[0]
        : last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14)
          ? words[1]
          : words[2])
    );
  }
  function productId(card) {
    const direct = id(card.dataset.productId);
    if (direct) return direct;
    for (const el of card.querySelectorAll("[data-product-id],[onclick],a[href]")) {
      const value = id(el.dataset.productId);
      if (value) return value;
      const match = (el.getAttribute("onclick") || "").match(/\bcart\s*\.\s*add\s*\(\s*['"]?(\d{1,10})/);
      if (match && id(match[1])) return id(match[1]);
      if (el.tagName === "A") {
        try {
          const value = id(new URL(el.href, document.baseURI).searchParams.get("product_id"));
          if (value) return value;
        } catch (_) {}
      }
    }
    return null;
  }
  function detail() {
    const root = document.getElementById("product");
    const input = root?.querySelector('input[name="product_id"]');
    if (!input || !id(input.value)) return null;
    const tab = document.getElementById("tab-review");
    const anchor = tab?.closest(".tab-content") || root;
    return { id: id(input.value), anchor };
  }
  function badge(card, product, data) {
    if (card.dataset.rfDemoId === String(product)) return;
    card.querySelector(":scope > .rf-demo-badge")?.remove();
    card.dataset.rfDemoId = String(product);
    if (!data || !data.demo_count) {
      card.classList.remove("rf-demo-has-badge");
      return;
    }
    card.classList.add("rf-demo-has-badge");
    const block = node("div", "rf-demo-badge");
    block.append(node("strong", "rf-demo-label", "ДЕМО · синтетические оценки"));
    block.append(
      node(
        "span",
        "rf-demo-value",
        "★ " +
          score(data.demo_average) +
          " / 5 · " +
          countLabel(data.demo_count, ["тестовая запись", "тестовые записи", "тестовых записей"]),
      ),
    );
    block.append(node("small", "", "Отдельно от оценок покупателей"));
    card.append(block);
  }
  function panel(target, data) {
    const previous = document.getElementById("rf-demo-product");
    if (!data || !data.demo_count) {
      previous?.remove();
      return;
    }
    if (previous?.dataset.productId === String(target.id)) return;
    previous?.remove();
    const section = node("section", "rf-demo-panel");
    section.id = "rf-demo-product";
    section.dataset.productId = String(target.id);
    section.setAttribute("aria-label", "Демонстрационные синтетические отзывы");
    section.append(node("h2", "", "Демонстрационные синтетические отзывы"));
    section.append(
      node(
        "p",
        "rf-demo-notice",
        "TEST · Все записи в этом блоке вымышленные. Это внутренние тестовые материалы магазина, прошедшие отдельную модерацию, а не отзывы покупателей.",
      ),
    );
    section.append(
      node(
        "p",
        "rf-demo-summary",
        "Демо-оценка: ★ " +
          score(data.demo_average) +
          " / 5 · " +
          countLabel(data.demo_count, [
            "синтетическая запись",
            "синтетические записи",
            "синтетических записей",
          ]),
      ),
    );
    for (const entry of data.entries || []) {
      const article = node("article", "rf-demo-entry");
      article.append(
        node("strong", "rf-demo-label", "ДЕМО · синтетическая запись, не отзыв покупателя"),
      );
      article.append(node("h3", "", "Вымышленный автор: " + entry.author));
      article.append(
        node(
          "p",
          "rf-demo-score",
          "Демо-оценка: " +
            "★".repeat(entry.demo_score) +
            "☆".repeat(5 - entry.demo_score) +
            " · " +
            entry.demo_score +
            "/5",
        ),
      );
      article.append(
        node(
          "p",
          "rf-demo-meta",
          "Модельная дата: " +
            entry.model_date +
            (entry.city ? " · Модельный город: " + entry.city : ""),
        ),
      );
      article.append(node("p", "rf-demo-body", entry.body));
      if (entry.excerpt) article.append(node("small", "", "Показан фрагмент тестовой записи."));
      section.append(article);
    }
    if ((data.entries || []).length < data.demo_count)
      section.append(
        node(
          "small",
          "",
          "Показаны " +
            (data.entries || []).length +
            " из " +
            data.demo_count +
            " одобренных демо-записей.",
        ),
      );
    target.anchor.insertAdjacentElement("afterend", section);
  }
  async function scan() {
    if (running) {
      needsScan = true;
      return;
    }
    const cards = [...document.querySelectorAll(".product-thumb")];
    if (!cards.length) cards.push(...document.querySelectorAll(".product-layout"));
    const mapped = cards.map((card) => ({ card, id: productId(card) })).filter((row) => row.id);
    const target = detail();
    const ids = [...new Set(mapped.map((row) => row.id).concat(target ? [target.id] : []))];
    const pending = ids.filter((value) => !requested.has(value));
    if (target && !detailRequested.has(target.id) && !pending.includes(target.id))
      pending.unshift(target.id);
    if (pending.length) {
      running = true;
      try {
        for (let offset = 0; offset < pending.length; offset += 40) {
          const batch = pending.slice(offset, offset + 40);
          batch.forEach((value) => requested.add(value));
          const url = new URL("index.php", document.baseURI);
          if (url.origin !== location.origin) return;
          url.searchParams.set("route", "extension/module/review_factory_demo");
          url.searchParams.set("ids", batch.join(","));
          if (target && batch.includes(target.id)) {
            detailRequested.add(target.id);
            url.searchParams.set("detail", String(target.id));
          }
          const controller = new AbortController(),
            timeout = setTimeout(() => controller.abort(), 10000);
          let response;
          try {
            response = await fetch(url, {
              credentials: "same-origin",
              cache: "no-store",
              signal: controller.signal,
              headers: { Accept: "application/json" },
            });
          } finally {
            clearTimeout(timeout);
          }
          if (!response.ok) continue;
          const result = await response.json();
          if (result.environment !== "test") continue;
          batch.forEach((value) => cache.set(value, result.products?.[value] || null));
        }
      } catch (_) {
        /* The native storefront remains usable if demo data is unavailable. */
      } finally {
        running = false;
        if (needsScan) {
          needsScan = false;
          schedule();
        }
      }
    }
    mapped.forEach((row) => {
      if (cache.has(row.id)) badge(row.card, row.id, cache.get(row.id));
    });
    if (target && cache.has(target.id)) panel(target, cache.get(target.id));
  }
  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(scan, 150);
  }
  function start() {
    scan();
    new MutationObserver(schedule).observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ["data-product-id", "onclick", "href", "value"],
    });
  }
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
