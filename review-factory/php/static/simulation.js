"use strict";
const simulationState = { ids: [], current: null, count: 1 };
let simulationSourceTimer = null;
const simulationMark = "ТЕСТОВЫЕ ДАННЫЕ · не отзывы покупателей";
function simulationBanner() {
  return `<div class="simulation-banner"><strong>${simulationMark}</strong><span>Закрытый стенд: вымышленные авторы и модельные даты/оценки. Записи хранятся отдельно. Для TEST-магазина предусмотрен явно помеченный демо-показ после отдельной модерации.</span></div>`;
}
function simulationRegistration(value) {
  return value === "model_guest" ? "ТЕСТ · Без регистрации" : "ТЕСТ · Проверенный отзыв";
}
function simulationAverage(value) {
  return value === null || value === undefined
    ? "—"
    : Number(value).toLocaleString("ru-RU", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function simulationShopMetrics(data) {
  const shop = data.shop_stats;
  return `<section class="simulation-shop-stats" aria-label="Отзывы магазина"><div class="eyebrow">ОПУБЛИКОВАННЫЕ ОТЗЫВЫ В ВАШЕМ TEST-МАГАЗИНЕ</div><div class="simulation-shop-numbers"><div><strong id="shop-review-count">${num(shop.count)}</strong><span>отзывов в магазине</span></div><div><strong id="shop-review-average">${simulationAverage(shop.average)}</strong><span>средняя оценка в магазине</span></div></div><p class="caption">${shop.updated_at ? "Снимок магазина: " + date(shop.updated_at) + ". Оценки из опубликованных записей; фильтры витрины могут исключать отзывы сотрудников." : "Данные магазина ещё не синхронизированы."} Тестовые карточки Комбайна сюда не входят.</p></section>`;
}
function simulationCombinedMetrics(data) {
  const stats = data.combined_stats,
    shop = data.shop_stats,
    model = data.model_stats;
  return `<section class="simulation-combined-stats" aria-label="Экспериментальное среднее"><div class="eyebrow">ЭКСПЕРИМЕНТ · МАГАЗИН + СИНТЕТИЧЕСКИЕ ЗАПИСИ</div><div class="product-meta"><div><strong id="combined-count">${num(stats.count)}</strong>записей в расчёте</div><div><strong id="combined-average">${simulationAverage(stats.average)}</strong>общее среднее</div><div><strong id="combined-projected-average">${simulationAverage(stats.after_current)}</strong>после этой карточки</div></div><p id="combined-formula" class="caption">${stats.count === null ? "Для общего расчёта сначала нужен снимок магазина." : `В магазине: ${num(shop.count)} · сохранено синтетических: ${num(model.count)}. ${stats.count ? `(${num(shop.rating_sum)} + ${num(model.rating_sum)}) ÷ (${num(shop.count)} + ${num(model.count)}) = ${simulationAverage(stats.average)}.` : "Обе базы пока не содержат записей этого товара."}`} Черновик учитывается только в прогнозе. Это экспериментальный показатель внутри Комбайна.</p></section>`;
}
function simulationMetrics(data) {
  const stats = data.model_stats;
  return `<div class="simulation-model-heading">СИНТЕТИЧЕСКИЕ ЗАПИСИ КОМБАЙНА · ОТДЕЛЬНЫЙ РАСЧЁТ</div><div class="product-meta"><div><strong id="model-count">${num(stats.count)}</strong>сохранено для этого товара</div><div><strong id="model-average">${simulationAverage(stats.average)}</strong>среднее этого товара</div><div><strong id="model-projected-average">${simulationAverage(stats.after_current)}</strong>после этой карточки</div></div><p class="caption simulation-math">${stats.count ? `Расчёт: ${num(stats.rating_sum)} ÷ ${num(stats.count)} = ${simulationAverage(stats.average)}.` : "У этого товара пока нет сохранённых тестовых записей."} Черновик учитывается только в прогнозе. Ориентир 4,4–4,8; первая целая оценка может быть вне диапазона.</p>`;
}
function simulationSourceContent(data) {
  const sync = data.source_sync || {};
  const freshness = sync.updated_at
    ? "Ссылки обновлены: " + date(sync.updated_at) + "."
    : "Ссылки из аналитики ещё не загружены в Комбайн.";
  return `<p class="caption">${esc(freshness)}${sync.error ? " Последнее обновление ссылок завершилось ошибкой." : ""}</p>${data.sources.map((source) => `<div class="source"><strong>${esc(sourceNames[source.source_id] || source.source_id)}</strong><p>${safeLink(source.url, "Открыть страницу товара")}</p><small>${source.review_count === null ? "Число отзывов пока неизвестно" : num(source.review_count) + " отзывов"}${source.average_rating === null ? "" : " · оценка источника " + simulationAverage(source.average_rating)}</small><p class="caption">${source.last_success ? "Данные получены: " + date(source.last_success) + "." : "Данные отзывов ещё не получены."}${source.error ? " Последняя попытка не удалась; отсутствие данных не означает отсутствие отзывов." : !source.last_attempt && !source.last_success ? " Ожидается запуск сборщика." : ""}</p>${source.error ? `<p class="caption">Причина: ${esc({ reviews_unrecognized: "разметка отзывов не распознана", blocked: "сайт ограничил доступ", rate_limited: "сайт просит уменьшить частоту запросов", robots_disallowed: "чтение запрещено robots.txt", timeout: "истекло время ожидания", robots_unavailable: "не удалось проверить robots.txt" }[source.error] || source.error)}</p>` : ""}<p class="caption">${source.requested_at ? "Запрос принят. Ожидаем фоновый сбор." : source.next_check && Date.parse(source.next_check) > Date.now() ? "Следующая проверка: " + date(source.next_check) + "." : ""}</p><button class="small" data-monitor-source="${esc(source.source_id)}" ${source.requested_at || (source.next_check && Date.parse(source.next_check) > Date.now()) ? "disabled" : ""}>${source.requested_at ? "Загрузка запрошена" : "Загрузить отзывы"}</button>${source.last_success && Number(source.review_count) > 0 && !(source.samples || []).length ? '<p class="caption">Счётчик получен, но тексты отзывов в ответе страницы не распознаны.</p>' : ""}${(source.samples || []).map((sample) => `<blockquote>${esc(sample)}</blockquote>`).join("")}</div>`).join("") || `<p class="caption">${sync.updated_at ? "Для этого товара подтверждённых страниц в загруженном списке нет. Ссылки других товаров сюда не подставляются." : "Подтверждённые ссылки ещё не загружены. Обновление выполняет администратор."}</p>`}`;
}
function simulationPollSources() {
  clearTimeout(simulationSourceTimer);
  if (state.view === "simulation" && simulationState.current?.sources.some((s) => s.requested_at))
    simulationSourceTimer = setTimeout(
      () => simulationRefresh(true).catch((err) => notice(err.message, true)),
      10000,
    );
}
async function simulationLoadSource(source, button) {
  const id = simulationState.current.entry.id;
  button.disabled = true;
  try {
    const result = await request("simulation/entries/" + id + "/sources/request", { source_id: source });
    await simulationRefresh(true);
    notice(
      result.state === "queued"
        ? "Запрос принят. Этот запрос получит приоритет при следующем сборе."
        : "Сохранённые данные ещё свежие. Следующая проверка: " + date(result.next_check),
    );
  } finally {
    if (button.isConnected) button.disabled = false;
  }
}
async function simulationRefresh(quiet = false) {
  if (state.busy || state.saving) {
    if (quiet) simulationPollSources();
    return;
  }
  const current = simulationState.current,
    id = current.entry.id;
  const fresh = await request("simulation/entries/" + id);
  if (state.view !== "simulation" || simulationState.current.entry.id !== id) return;
  current.model_stats = fresh.model_stats;
  current.shop_stats = fresh.shop_stats;
  current.combined_stats = fresh.combined_stats;
  current.sources = fresh.sources;
  current.source_sync = fresh.source_sync;
  $("#simulation-combined-metrics").innerHTML = simulationCombinedMetrics(current);
  $("#simulation-shop-metrics").innerHTML = simulationShopMetrics(current);
  $("#simulation-metrics").innerHTML = simulationMetrics(current);
  $("#simulation-source-content").innerHTML = simulationSourceContent(current);
  simulationPollSources();
  if (!quiet) notice("Показатели и сохранённые источники обновлены.");
}
async function simulationWork(count = simulationState.count) {
  if (state.busy || state.saving) return;
  simulationState.count = count;
  const sequence = ++state.navigation;
  const data = await request("simulation/claim", { count });
  if (state.view !== "simulation" || sequence !== state.navigation) return;
  simulationState.ids = data.ids;
  if (data.ids.length) await simulationOpen(data.ids[0]);
  else
    main.innerHTML =
      intro("Очередь тестовых отзывов.", "Товары для проверки формы и сценария заполнения.") +
      simulationBanner() +
      `<div class="empty"><h2>Пока нет свободных товаров</h2><p>Нужен загруженный каталог товаров. Если товары уже в работе у коллег, их карточки закреплены за ними.</p><button data-view="simulation">Обновить очередь</button></div>`;
}
async function simulationOpen(id) {
  if (state.busy || state.saving) return;
  if (state.dirty && !confirm("Есть несохранённый тестовый текст. Перейти без сохранения?")) return;
  state.dirty = false;
  const sequence = ++state.navigation;
  const data = await request("simulation/entries/" + id);
  if (state.view !== "simulation" || sequence !== state.navigation) return;
  simulationState.current = data;
  simulationPaint();
}
function simulationPaint() {
  const d = simulationState.current,
    e = d.entry,
    p = d.product;
  main.innerHTML =
    intro(
      "Товар → текст → следующий.",
      "Текст и все поля уже заполнены автоматически. При желании текст можно изменить.",
    ) +
    simulationBanner() +
    `<div class="simulation-toolbar"><span class="caption">Оператор: ${esc(state.user.name)} · в работе ${simulationState.ids.length}</span><label>Взять пакет<select id="simulation-batch">${[1, 5, 10].map((n) => `<option value="${n}" ${n === simulationState.count ? "selected" : ""}>${n} ${n === 1 ? "товар" : "товаров"}</option>`).join("")}</select></label><button id="simulation-refresh">Обновить данные</button><button id="simulation-preview">Закрытый предпросмотр</button>${simulationState.ids.length > 1 ? '<button id="simulation-packet" class="primary">Сохранить весь тестовый пакет</button>' : ""}</div><div class="assignment-strip">${simulationState.ids.map((id, i) => `<button data-simulation-open="${id}" class="${id === e.id ? "active" : ""}">Карточка ${i + 1}</button>`).join("")}</div><div class="workspace simulation-workspace" data-pane="write"><div class="mobile-pane-nav" role="tablist" aria-label="Работа с товаром"><button role="tab" aria-selected="true" data-pane="write">Пишу тестовый текст</button><button role="tab" aria-selected="false" data-pane="reference">Подсказки</button></div><section class="panel"><div class="product-heading">${p.image ? `<img src="${esc(p.image)}" alt="">` : ""}<div><div class="eyebrow">${esc(p.category || "ТОВАР ТЕСТОВОГО КАТАЛОГА")}</div><h2>${esc(p.name)}</h2>${safeLink(p.url, "Исходная карточка товара")}</div></div><div id="simulation-combined-metrics">${simulationCombinedMetrics(d)}</div><div id="simulation-shop-metrics">${simulationShopMetrics(d)}</div><div id="simulation-metrics">${simulationMetrics(d)}</div><div class="panel-body"><dl class="simulation-fields"><div><dt>Вымышленный автор</dt><dd id="model-author">${esc(e.model_author)}</dd></div><div><dt>Модельная группа</dt><dd id="model-registration">${esc(simulationRegistration(e.registration))}</dd></div><div><dt>Модельный город</dt><dd id="model-city">${esc(e.model_city || "Без города")}</dd></div><div><dt>Модельная дата</dt><dd id="model-date">${esc(e.model_date)}</dd></div><div><dt>Автоматические звёзды</dt><dd id="model-rating" aria-label="Модельная оценка ${e.rating} из 5">${"★".repeat(Number(e.rating))}${"☆".repeat(5 - Number(e.rating))} · ${num(e.rating)}/5</dd></div><div><dt>Автоматическое настроение</dt><dd id="model-mood">${esc(e.mood)}</dd></div></dl><div class="writing-tip"><div><strong>${esc(depths[d.guidance.depth] || depths.normal)}</strong>Можно проверить описание вкуса, аромата, упаковки или способа применения. Это подсказка, не обязательная квота.</div></div><form id="simulation-writer" method="post"><label for="simulation-body">Текст тестового отзыва</label><textarea id="simulation-body" maxlength="10000" placeholder="Введите текст для проверки карточки…">${esc(e.body)}</textarea><div class="writer-footer"><span id="simulation-words">0 слов</span><span id="simulation-saved">${e.body ? "Тестовый черновик загружен" : "Тестовый текст и автополя заполнены"}</span></div><div class="actions"><button type="button" id="simulation-draft">Сохранить черновик</button><button class="primary" type="submit">Сохранить тестовый отзыв →</button></div><p class="after-note">Запись сохранится для закрытого предпросмотра и демо-модерации, затем откроется следующий товар. Демо-показ в TEST-магазине требует отдельного одобрения.</p><button type="button" id="simulation-skip" class="quiet small">Пропустить товар</button></form></div></section><aside class="panel references"><div class="aside-title"><h2>Подсказки по товару</h2></div><div class="panel-body"><details id="simulation-sources" open><summary>Источники мониторинга</summary><div id="simulation-source-content">${simulationSourceContent(d)}</div></details><details><summary>Описание и характеристики</summary><p>${esc(p.description || "Описание не загружено.")}</p></details><details><summary>Что проверяет этот режим</summary><p>Очередь товаров, автоматическое заполнение, сохранение текста и отображение тестовых записей. Имена не берутся из базы клиентов. Рейтинг покупателей не меняется.</p></details></div></aside></div>`;
  simulationWords();
  simulationPollSources();
}
function simulationWords() {
  const n = ($("#simulation-body")?.value.match(/[\p{L}\p{N}]+(?:[’'-][\p{L}\p{N}]+)*/gu) || []).length;
  if ($("#simulation-words")) $("#simulation-words").textContent = n + " слов";
}
async function simulationSaveDraft() {
  if (state.saving) return state.saving;
  const data = simulationState.current,
    e = data.entry,
    body = $("#simulation-body").value;
  state.saving = request("simulation/entries/" + e.id + "/draft", { body, revision: Number(e.revision) })
    .then((saved) => {
      data.entry = saved;
      state.dirty = $("#simulation-body").value !== body;
      $("#simulation-saved").textContent = state.dirty
        ? "Есть новые изменения"
        : "Тестовый черновик сохранён";
      return saved;
    })
    .finally(() => (state.saving = null));
  return state.saving;
}
async function simulationFinish() {
  if (state.busy) return;
  state.busy = true;
  try {
    if (state.saving) await state.saving;
    const e = simulationState.current.entry,
      body = $("#simulation-body").value;
    document
      .querySelectorAll("#simulation-writer button,#simulation-body")
      .forEach((b) => (b.disabled = true));
    await request("simulation/entries/" + e.id + "/save", { body, revision: Number(e.revision) });
    state.dirty = false;
    state.busy = false;
    notice("Тестовая запись сохранена в закрытой базе.");
    await simulationWork();
  } catch (err) {
    notice(err.message, true);
  } finally {
    state.busy = false;
    document
      .querySelectorAll("#simulation-writer button,#simulation-body")
      .forEach((b) => (b.disabled = false));
  }
}
async function simulationSavePacket() {
  if (state.busy) return;
  state.busy = true;
  let savedCount = 0;
  try {
    if (state.saving) await state.saving;
    document
      .querySelectorAll(
        ".simulation-toolbar button,.simulation-toolbar select,#simulation-writer button,#simulation-body",
      )
      .forEach((b) => (b.disabled = true));
    if (state.dirty) await simulationSaveDraft();
    for (const id of [...simulationState.ids]) {
      const data = await request("simulation/entries/" + id),
        entry = data.entry;
      if (entry.state === "saved") continue;
      await request("simulation/entries/" + id + "/save", {
        body: entry.body,
        revision: Number(entry.revision),
      });
      savedCount++;
    }
    state.dirty = false;
    state.busy = false;
    notice("Сохранено тестовых записей: " + savedCount + ". Открываем следующий пакет.");
    await simulationWork();
  } catch (err) {
    notice("Сохранено в этом запуске: " + savedCount + ". " + err.message, true);
  } finally {
    state.busy = false;
    document
      .querySelectorAll(
        ".simulation-toolbar button,.simulation-toolbar select,#simulation-writer button,#simulation-body",
      )
      .forEach((b) => (b.disabled = false));
  }
}
async function simulationPreview(product) {
  if (state.saving || state.busy) return;
  if (
    state.dirty &&
    !confirm("Предпросмотр показывает сохранённые записи. Перейти без сохранения текущего текста?")
  )
    return;
  state.dirty = false;
  const data = await request("simulation/preview/" + product);
  if (state.view !== "simulation" && state.view !== "simulation-history") return;
  main.innerHTML =
    navigation() +
    simulationBanner() +
    `<section class="simulation-preview"><div class="eyebrow">ЗАКРЫТЫЙ ПРЕДПРОСМОТР · ТОЛЬКО СИНТЕТИЧЕСКИЕ ЗАПИСИ</div><h1>${esc(data.product?.name || "Тестовая карточка товара")}</h1><p id="simulation-preview-combined" class="caption">ЭКСПЕРИМЕНТ · магазин + сохранённые синтетические: ${num(data.combined_stats.count)} записей · общее среднее ${simulationAverage(data.combined_stats.average)}.</p><p id="simulation-preview-stats" class="caption">Сохранено для этого товара: ${num(data.stats.count)} · среднее: ${simulationAverage(data.stats.average)}${data.stats.count ? " · " + num(data.stats.rating_sum) + " ÷ " + num(data.stats.count) : ""}</p><button data-view="simulation">← Вернуться к очереди</button><div class="simulation-preview-list">${data.entries.map((e) => `<article class="panel panel-body"><div class="simulation-entry-mark">${simulationMark}</div><h2>Тестовый автор: ${esc(e.model_author)}</h2><span class="status">${esc(simulationRegistration(e.registration))}</span><p class="caption">Модельная дата: ${esc(e.model_date)}${e.model_city ? " · Модельный город: " + esc(e.model_city) : ""}</p><div class="simulation-stars" aria-label="Демонстрационная оценка ${e.rating} из 5">${"★".repeat(Number(e.rating))}${"☆".repeat(5 - Number(e.rating))}</div><p class="simulation-text">${esc(e.body)}</p></article>`).join("") || '<p class="caption top-space">У этого товара ещё нет сохранённых тестовых записей.</p>'}</div></section>`;
}
async function simulationHistory() {
  const data = await request("simulation/summary");
  if (state.view !== "simulation-history") return;
  const s = data.stats;
  main.innerHTML =
    intro(
      "Сохранённые тестовые записи.",
      "Это результаты проверки закрытого стенда, а не отзывы покупателей.",
    ) +
    simulationBanner() +
    `<div class="stats-grid">${[
      ["Тестовых записей", s.total],
      ["Среднее по всем тестовым", s.average],
      ["Модельных гостей", s.guests || 0],
      ["Без модельного города", s.no_city || 0],
    ]
      .map(
        ([k, v]) =>
          `<div class="metric"><small>${k}</small><strong>${k === "Среднее по всем тестовым" ? simulationAverage(v) : num(v)}</strong></div>`,
      )
      .join(
        "",
      )}</div><h2>Средние по товарам</h2><p class="caption">Синтетические показатели рассчитаны по сохранённым записям всех операторов. В колонке «Эксперимент» добавлены опубликованные отзывы этого товара из снимка магазина.</p><div class="table-wrap"><table id="simulation-product-stats"><thead><tr><th>Товар</th><th>Записей</th><th>Расчёт среднего</th><th>Эксперимент: магазин + синтетика</th></tr></thead><tbody>${data.per_product.map((product) => `<tr><td>${esc(product.product_name)}</td><td>${num(product.count)}</td><td>${num(product.rating_sum)} ÷ ${num(product.count)} = ${simulationAverage(product.average)}</td><td>${num(product.combined_stats.count)} записей · ${simulationAverage(product.combined_stats.average)}</td></tr>`).join("") || '<tr><td colspan="4">Сохранённых записей ещё нет.</td></tr>'}</tbody></table></div><h2>Мои тестовые карточки</h2><div class="table-wrap"><table id="simulation-my-records"><thead><tr><th>Товар и тестовый текст</th><th>Автополя</th><th>Состояние</th></tr></thead><tbody>${data.items.map((e) => `<tr><td><strong>${esc(e.product_name)}</strong><p>${esc(e.excerpt)}</p><button data-simulation-preview="${e.product_id}" class="small">Закрытый предпросмотр</button>${e.state === "draft" ? `<button data-simulation-resume="${e.id}" class="small">Продолжить тестовый текст</button>` : ""}</td><td>${esc(e.model_author)}<br>${num(e.rating)}/5 · ${esc(e.model_date)}<br>${esc(e.model_city || "Без города")}<br>${esc(simulationRegistration(e.registration))}</td><td>${esc({ draft: "Тестовый черновик", saved: "Тестовая запись сохранена", skipped: "Пропущено" }[e.state])}${e.demo_state ? `<p class="caption">Демо: ${esc({ pending: "ожидает модерации", approved: "одобрено для демо", hidden: "не показывать" }[e.demo_state])}</p>` : ""}</td></tr>`).join("") || '<tr><td colspan="3">Тестовых карточек пока нет.</td></tr>'}</tbody></table></div>`;
}
document.addEventListener("submit", (e) => {
  if (e.target.id === "simulation-writer") {
    e.preventDefault();
    simulationFinish();
  }
});
document.addEventListener("input", (e) => {
  if (e.target.id === "simulation-body") {
    state.dirty = true;
    $("#simulation-saved").textContent = "Есть несохранённый тестовый текст";
    simulationWords();
  }
});
document.addEventListener("change", async (e) => {
  if (e.target.id === "simulation-batch") {
    if (state.dirty && !confirm("Перейти к пакету без сохранения текста?")) return;
    try {
      await simulationWork(Number(e.target.value));
    } catch (err) {
      notice(err.message, true);
    }
  }
});
document.addEventListener("click", async (e) => {
  const b = e.target.closest("button");
  if (!b) return;
  try {
    if (b.dataset.simulationOpen) await simulationOpen(b.dataset.simulationOpen);
    if (b.dataset.simulationResume) {
      state.view = "simulation";
      simulationState.ids = [b.dataset.simulationResume];
      await simulationOpen(b.dataset.simulationResume);
    }
    if (b.dataset.simulationPreview) await simulationPreview(Number(b.dataset.simulationPreview));
    if (b.id === "simulation-preview")
      await simulationPreview(Number(simulationState.current.entry.product_id));
    if (b.id === "simulation-refresh") await simulationRefresh();
    if (b.dataset.monitorSource) await simulationLoadSource(b.dataset.monitorSource, b);
    if (b.id === "simulation-packet") await simulationSavePacket();
    if (b.id === "simulation-draft") {
      await simulationSaveDraft();
      notice("Тестовый черновик сохранён.");
    }
    if (b.id === "simulation-skip") {
      if (state.dirty) {
        await simulationSaveDraft();
      }
      await request("simulation/entries/" + simulationState.current.entry.id + "/skip", {});
      state.dirty = false;
      await simulationWork();
    }
  } catch (err) {
    notice(err.message, true);
  }
});
document.addEventListener("keydown", (e) => {
  if ($("#simulation-writer") && e.ctrlKey && e.key === "Enter") {
    e.preventDefault();
    simulationFinish();
  }
});
