"use strict";
const simulationState = { ids: [], current: null, count: 1 };
const simulationMark = "ТЕСТОВЫЕ ДАННЫЕ · не отзывы покупателей";
function simulationBanner() {
  return `<div class="simulation-banner"><strong>${simulationMark}</strong><span>Закрытый стенд: вымышленные авторы и модельные даты/оценки. Эти записи хранятся отдельно и не публикуются в магазине.</span></div>`;
}
function simulationRegistration(value) {
  return value === "model_guest" ? "ТЕСТ · Без регистрации" : "ТЕСТ · Проверенный отзыв";
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
    p = d.product,
    mean = d.model_stats.average;
  const sources = {
    deluxe: "Deluxe",
    spicery: "Spicery Shop",
    banka: "Банка Спецій",
    specii: "Лавка прянощів",
  };
  main.innerHTML =
    intro(
      "Товар → текст → следующий.",
      "Текст и все поля уже заполнены автоматически. При желании текст можно изменить.",
    ) +
    simulationBanner() +
    `<div class="simulation-toolbar"><span class="caption">Оператор: ${esc(state.user.name)} · в работе ${simulationState.ids.length}</span><label>Взять пакет<select id="simulation-batch">${[1, 5, 10].map((n) => `<option value="${n}" ${n === simulationState.count ? "selected" : ""}>${n} ${n === 1 ? "товар" : "товаров"}</option>`).join("")}</select></label><button id="simulation-preview">Закрытый предпросмотр</button>${simulationState.ids.length > 1 ? '<button id="simulation-packet" class="primary">Сохранить весь тестовый пакет</button>' : ""}</div><div class="assignment-strip">${simulationState.ids.map((id, i) => `<button data-simulation-open="${id}" class="${id === e.id ? "active" : ""}">Карточка ${i + 1}</button>`).join("")}</div><div class="workspace simulation-workspace" data-pane="write"><div class="mobile-pane-nav" role="tablist" aria-label="Работа с товаром"><button role="tab" aria-selected="true" data-pane="write">Пишу тестовый текст</button><button role="tab" aria-selected="false" data-pane="reference">Подсказки</button></div><section class="panel"><div class="product-heading">${p.image ? `<img src="${esc(p.image)}" alt="">` : ""}<div><div class="eyebrow">${esc(p.category || "ТОВАР ТЕСТОВОГО КАТАЛОГА")}</div><h2>${esc(p.name)}</h2>${safeLink(p.url, "Исходная карточка товара")}</div></div><div class="product-meta"><div><strong>${num(d.model_stats.count)}</strong>сохранено тестовых</div><div><strong>${num(mean)}</strong>модельное среднее</div><div><strong>4,4–4,8</strong>ориентир симуляции</div></div><div class="panel-body"><dl class="simulation-fields"><div><dt>Вымышленный автор</dt><dd id="model-author">${esc(e.model_author)}</dd></div><div><dt>Модельная группа</dt><dd id="model-registration">${esc(simulationRegistration(e.registration))}</dd></div><div><dt>Модельный город</dt><dd id="model-city">${esc(e.model_city || "Без города")}</dd></div><div><dt>Модельная дата</dt><dd id="model-date">${esc(e.model_date)}</dd></div><div><dt>Автоматические звёзды</dt><dd id="model-rating" aria-label="Модельная оценка ${e.rating} из 5">${"★".repeat(Number(e.rating))}${"☆".repeat(5 - Number(e.rating))} · ${num(e.rating)}/5</dd></div><div><dt>Автоматическое настроение</dt><dd id="model-mood">${esc(e.mood)}</dd></div></dl><div class="writing-tip"><div><strong>${esc(depths[d.guidance.depth] || depths.normal)}</strong>Можно проверить описание вкуса, аромата, упаковки или способа применения. Это подсказка, не обязательная квота.</div></div><form id="simulation-writer" method="post"><label for="simulation-body">Текст тестового отзыва</label><textarea id="simulation-body" maxlength="10000" placeholder="Введите текст для проверки карточки…">${esc(e.body)}</textarea><div class="writer-footer"><span id="simulation-words">0 слов</span><span id="simulation-saved">${e.body ? "Тестовый черновик загружен" : "Тестовый текст и автополя заполнены"}</span></div><div class="actions"><button type="button" id="simulation-draft">Сохранить черновик</button><button class="primary" type="submit">Сохранить тестовый отзыв →</button></div><p class="after-note">Запись появится в закрытом предпросмотре, затем откроется следующий товар. В отзывы магазина она не передаётся.</p><button type="button" id="simulation-skip" class="quiet small">Пропустить товар</button></form></div></section><aside class="panel references"><div class="aside-title"><h2>Подсказки по товару</h2></div><div class="panel-body"><details><summary>Описание и характеристики</summary><p>${esc(p.description || "Описание не загружено.")}</p></details><details><summary>Источники мониторинга</summary><p class="caption">Сохранённые ссылки из аналитики. Ошибка чтения и неизвестное число отзывов не означают ноль.</p>${d.sources.map((s) => `<div class="source">${esc(sources[s.source_id] || s.source_id)} · ${Number(s.confirmed) ? "соответствие подтверждено" : "соответствие не подтверждено"}<p>${safeLink(s.url, "Открыть источник")}</p><small>${s.review_count === null ? "Количество отзывов неизвестно" : num(s.review_count) + " отзывов"} · ${date(s.last_success)}${s.error ? " · чтение требует проверки" : ""}</small></div>`).join("") || '<p class="caption">Подтверждённых ссылок пока нет в сохранённом снимке.</p>'}</details><details><summary>Что проверяет этот режим</summary><p>Очередь товаров, автоматическое заполнение, сохранение текста и отображение тестовых записей. Имена не берутся из базы клиентов. Рейтинг покупателей не меняется.</p></details></div></aside></div>`;
  simulationWords();
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
    `<section class="simulation-preview"><div class="eyebrow">ЗАКРЫТЫЙ ПРЕДПРОСМОТР · ТОЛЬКО СИНТЕТИЧЕСКИЕ ЗАПИСИ</div><h1>${esc(data.product?.name || "Тестовая карточка товара")}</h1><button data-view="simulation">← Вернуться к очереди</button><div class="simulation-preview-list">${data.entries.map((e) => `<article class="panel panel-body"><div class="simulation-entry-mark">${simulationMark}</div><h2>Тестовый автор: ${esc(e.model_author)}</h2><span class="status">${esc(simulationRegistration(e.registration))}</span><p class="caption">Модельная дата: ${esc(e.model_date)}${e.model_city ? " · Модельный город: " + esc(e.model_city) : ""}</p><div class="simulation-stars" aria-label="Демонстрационная оценка ${e.rating} из 5">${"★".repeat(Number(e.rating))}${"☆".repeat(5 - Number(e.rating))}</div><p class="simulation-text">${esc(e.body)}</p></article>`).join("") || '<p class="caption top-space">У этого товара ещё нет сохранённых тестовых записей.</p>'}</div></section>`;
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
      ["Модельное среднее", s.average],
      ["Модельных гостей", s.guests || 0],
      ["Без модельного города", s.no_city || 0],
    ]
      .map(([k, v]) => `<div class="metric"><small>${k}</small><strong>${num(v)}</strong></div>`)
      .join(
        "",
      )}</div><div class="table-wrap"><table><thead><tr><th>Товар и тестовый текст</th><th>Автополя</th><th>Состояние</th></tr></thead><tbody>${data.items.map((e) => `<tr><td><strong>${esc(e.product_name)}</strong><p>${esc(e.excerpt)}</p><button data-simulation-preview="${e.product_id}" class="small">Закрытый предпросмотр</button>${e.state === "draft" ? `<button data-simulation-resume="${e.id}" class="small">Продолжить тестовый текст</button>` : ""}</td><td>${esc(e.model_author)}<br>${num(e.rating)}/5 · ${esc(e.model_date)}<br>${esc(e.model_city || "Без города")}<br>${esc(simulationRegistration(e.registration))}</td><td>${esc({ draft: "Тестовый черновик", saved: "Тестовая запись сохранена", skipped: "Пропущено" }[e.state])}</td></tr>`).join("") || '<tr><td colspan="3">Тестовых карточек пока нет.</td></tr>'}</tbody></table></div>`;
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
