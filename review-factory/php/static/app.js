"use strict";
const $ = (s) => document.querySelector(s),
  main = $("#main");
const state = {
  user: null,
  csrf: "",
  view: "write",
  product: null,
  assignments: [],
  dirty: false,
  busy: false,
  saving: null,
  navigation: 0,
  batch: 1,
  health: {},
  environment: "test",
};
const sourceNames = {
  deluxe: "Deluxe",
  spicery: "Spicery Shop",
  banka: "Банка Спецій",
  specii: "Лавка прянощів",
};
const labels = {
  draft: "Черновик",
  submitted: "Ожидает отправки",
  moderation: "На модерации в магазине",
  published: "Опубликовано",
  rejected: "Удалено из магазина",
  historical: "Материал TEST · архив",
};
const depths = {
  short: "Короткий · 5–30 слов",
  normal: "Обычный · 31–100 слов",
  deep: "Подробный · более 100 слов",
};
const esc = (v) =>
  String(v ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ],
  );
const date = (v) =>
  v
    ? new Date(v).toLocaleString("ru-RU", {
        timeZone: "Europe/Kyiv",
        day: "numeric",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
      })
    : "ещё нет данных";
const num = (v) =>
  v === null || v === undefined
    ? "—"
    : Number(v).toLocaleString("ru-RU", { maximumFractionDigits: 1 });
const safeLink = (url, label) => {
  try {
    const u = new URL(url);
    if (u.protocol === "https:" && !u.username && !u.password)
      return `<a href="${esc(u.href)}" target="_blank" rel="noopener noreferrer">${esc(label)} →</a>`;
  } catch {}
  return esc(label);
};
function notice(message, error = false) {
  const n = $("#notice");
  n.textContent = message;
  n.className = error ? "error" : "";
  n.style.display = "block";
  clearTimeout(notice.timer);
  notice.timer = setTimeout(
    () => {
      n.style.display = "none";
    },
    error ? 12000 : 6500,
  );
}
async function request(route, body) {
  const r = await fetch("?route=" + encodeURIComponent(route), {
    method: body === undefined ? "GET" : "POST",
    headers:
      body === undefined
        ? {}
        : { "Content-Type": "application/json", "X-CSRF-Token": state.csrf },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  let data;
  try {
    data = await r.json();
  } catch {
    throw Error("Сервер временно недоступен. Текст остаётся на странице.");
  }
  if (!r.ok) {
    if (r.status === 401 && state.user) {
      notice(
        "Сессия завершилась. Скопируйте несохранённый текст и войдите снова.",
        true,
      );
    }
    throw Error(data.error || "Не удалось выполнить действие");
  }
  return data;
}
function healthRows(rows) {
  state.health = Object.fromEntries((rows || []).map((r) => [r.key, r.value]));
}
function navigation() {
  return `<nav aria-label="Разделы"><button data-view="write" class="${state.view === "write" ? "active" : ""}">Написать материал</button><button data-view="drafts" class="${state.view === "drafts" ? "active" : ""}">Мои материалы</button><button data-view="stats" class="${state.view === "stats" ? "active" : ""}">Результаты</button>${state.user.role === "admin" ? `<button data-view="team" class="${state.view === "team" ? "active" : ""}">Команда и настройки</button><button data-view="queue" class="${state.view === "queue" ? "active" : ""}">Очередь товаров</button>` : ""}</nav>`;
}
function intro(title, description) {
  return `${navigation()}<div class="intro"><div><div class="eyebrow">ОПЫТ КОМАНДЫ · ЛАВКА СПЕЦИЙ</div><h1>${title}</h1><p>${description}</p></div><span class="environment">${state.environment === "production" ? "РАБОЧИЙ САЙТ" : "TEST · РАЗРАБОТКА"}</span></div>`;
}
async function start() {
  try {
    const r = await request("me");
    state.user = r.user;
    state.csrf = r.csrf;
    state.environment = r.environment;
    healthRows(r.health);
    $("#account").innerHTML =
      `<span>${esc(state.user.name)}</span><button class="quiet small" id="logout">Выйти</button>`;
    await show("write");
  } catch (e) {
    if (state.user) notice(e.message, true);
  }
}
async function show(view) {
  if (state.saving || state.busy) {
    notice("Дождитесь завершения сохранения.");
    return;
  }
  ++state.navigation;
  if (
    state.dirty &&
    !confirm("Есть несохранённый текст. Перейти без сохранения?")
  )
    return;
  state.dirty = false;
  state.view = view;
  state.product = null;
  main.innerHTML =
    navigation() + '<div class="skeleton">Загружаем мастерскую…</div>';
  try {
    if (view === "write") await loadWork();
    else if (view === "drafts") await renderDrafts();
    else if (view === "stats") await renderStats();
    else if (view === "team") await renderTeam();
    else if (view === "queue") await renderQueue();
  } catch (e) {
    main.innerHTML =
      navigation() +
      `<div class="empty"><h2>Не удалось открыть раздел</h2><p>${esc(e.message)}</p><button data-view="${esc(view)}">Повторить</button></div>`;
  }
}
async function loadWork(count = 1) {
  state.batch = count;
  const r = await request("claim", { count });
  state.assignments = (await request("assignments")).items;
  if (state.view !== "write") return;
  const ids = r.ids;
  if (ids.length) await loadProduct(ids[0]);
  else
    main.innerHTML =
      intro(
        "Всё готово к следующему шагу.",
        "Здесь появляются товары, которым сейчас полезен опыт команды.",
      ) +
      `<div class="empty"><div class="eyebrow">НЕТ СВОБОДНЫХ ТОВАРОВ</div><h2>Пока можно сделать паузу</h2><p>Каталог может ожидать первой загрузки, товары — быть в работе у коллег или на паузе после отправки. Администратор видит состояние загрузки и назначения.</p><button data-view="drafts">Открыть мои материалы</button></div>`;
}
async function loadProduct(id) {
  if (state.saving || (state.busy && state.product)) {
    notice("Дождитесь завершения отправки.");
    return;
  }
  if (state.dirty && !confirm("Перейти к другому товару без сохранения?"))
    return;
  state.dirty = false;
  const sequence = ++state.navigation;
  const p = await request("products/" + id);
  if (sequence !== state.navigation) return;
  state.product = p;
  renderWork();
}
function sourceCard(id) {
  const c = state.product.competitors.find((c) => c.source_id === id);
  const eligible = c && Number(c.confirmed) && !c.stale && !c.error;
  let note = !c
    ? "Нет сопоставленной карточки"
    : !Number(c.confirmed)
      ? "Соответствие не подтверждено"
      : c.error
        ? "Не удалось прочитать страницу"
        : c.stale
          ? "Сведения требуют обновления"
          : "Отзывы на странице товара";
  return `<div class="source ${c?.error ? "error" : ""}">${esc(sourceNames[id])}<strong>${c?.review_count !== null && c?.review_count !== undefined ? num(c.review_count) : "—"} <span class="caption">отзывов</span></strong><small>${c?.average_rating ? `Оценка ${num(c.average_rating)} / 5 · ` : ""}${esc(note)}</small><small class="caption">${c?.last_success ? date(c.last_success) : "Успешного чтения ещё не было"}</small>${c ? safeLink(c.url, "Источник") : ""}${c && !eligible && c.review_count !== null ? "<small>В приоритете сейчас не учитывается</small>" : ""}</div>`;
}
function renderWork() {
  const p = state.product,
    d = p.draft || {};
  main.innerHTML =
    intro(
      "Делимся тем, что знаем.",
      "Один товар, ваш опыт — и полезная заметка для покупателя.",
    ) +
    `<div class="toolbar"><small>В работе: ${state.assignments.length} · приоритет товара ${num(p.priority)} / 100${Number(p.priority) > 100 ? " с ручной поправкой" : ""}</small><label>Взять пакет<select id="batch" aria-label="Количество товаров">${[1, 5, 10].map((n) => `<option value="${n}" ${state.batch === n ? "selected" : ""}>${n} ${n === 1 ? "товар" : "товаров"}</option>`).join("")}</select></label></div><div class="assignment-strip" aria-label="Товары в работе">${state.assignments.map((a) => `<button data-product="${a.id}" class="${a.id === p.id ? "active" : ""}">${esc(a.name)}</button>`).join("")}</div><div class="workspace" data-pane="write"><div class="mobile-pane-nav" role="tablist" aria-label="Работа с товаром"><button role="tab" aria-selected="true" data-pane="write">Пишу материал</button><button role="tab" aria-selected="false" data-pane="reference">Смотрю подсказки</button></div><section class="panel" aria-label="Подготовка материала"><div class="product-heading">${p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : ""}<div><div class="eyebrow">${esc(p.category || "ТОВАР МАГАЗИНА")}</div><h2>${esc(p.name)}</h2>${safeLink(p.url, "Открыть карточку товара")}</div></div><div class="product-meta"><div><strong>${num(p.customer_count)}</strong>отзывов покупателей</div><div><strong>${num(p.customer_rating)}</strong>оценка покупателей</div><div><strong>${num(p.sales90)}</strong>в заказах за 90 дней</div></div><form method="post" id="writer" class="panel-body"><div class="author-note"><strong>${esc(state.user.name)} · ${esc(state.user.staff_role)} Лавки</strong>Авторство будет видно в карточке товара. Дату публикации поставит магазин при активации.</div><fieldset class="rating"><legend>Ваша оценка по собственному опыту</legend><div class="stars">${[1, 2, 3, 4, 5].map((n) => `<label><input type="radio" name="rating" aria-label="${n} из 5" value="${n}" ${Number(d.rating) === n ? "checked" : ""}><span aria-hidden="true">★</span></label>`).join("")}</div><div class="caption">От 1 до 5; на рейтинг независимых покупателей не влияет.</div></fieldset><div class="writing-tip"><span aria-hidden="true">✳</span><div><strong>Подсказка по объёму: ${esc(depths[p.guidance.depth] || depths.normal)}</strong>${p.guidance.missing.length ? "Можно раскрыть: " + esc(p.guidance.missing.join(", ")) + "." : "Добавьте конкретный пример из своего опыта."} Это ориентир, не обязательная квота.</div></div><label for="body">Что вы заметили в этом товаре?</label><textarea id="body" name="body" maxlength="10000" placeholder="Расскажите о вкусе, аромате или о том, как используете этот товар…">${esc(d.body || "")}</textarea><div class="writer-footer"><span id="word-count">0 слов</span><span id="save-state">${d.id ? "Черновик загружен" : "Черновик ещё не сохранён"}</span></div><div class="fields"><label>Настроение<select name="sentiment">${Object.entries(
      {
        positive: "Положительное",
        neutral: "Нейтральное",
        mixed: "Смешанное",
        critical: "Критическое",
      },
    )
      .map(
        ([v, l]) =>
          `<option value="${v}" ${d.sentiment === v ? "selected" : ""}>${l}</option>`,
      )
      .join(
        "",
      )}</select></label><label>Реальный город · необязательно<input name="city" maxlength="80" value="${esc(d.city || "")}" autocomplete="address-level2"></label></div><details class="optional"><summary>Указать дату опыта использования</summary><label class="top-space">Фактическая дата опыта<input type="date" name="experience_date" value="${esc(d.experience_date || "")}" max="${new Date().toISOString().slice(0, 10)}"></label><p class="caption">Она будет отдельно от даты публикации.</p></details><div class="actions"><button type="button" id="save">Сохранить черновик</button><button type="button" id="preview-button">Просмотр</button><button class="primary" type="submit">Отправить на модерацию →</button></div><p class="after-note">После отправки откроется следующий товар. Публикацию активирует администратор в ocStore.</p><button type="button" class="quiet small" id="skip">Пропустить без текста</button></form></section><aside class="panel references" aria-label="Материалы для справки"><div class="aside-title"><h2>Перед глазами — всё нужное</h2><span class="caption">СПРАВКА</span></div><div class="panel-body"><div class="eyebrow">ЧЕТЫРЕ КОНКУРЕНТА</div><div class="source-grid">${Object.keys(sourceNames).map(sourceCard).join("")}</div><p class="caption">«—» означает неизвестное значение. Ноль показываем только после подтверждённого чтения. Данные обновляются раз в сутки.</p><details><summary>Описание и характеристики</summary><p>${esc(p.description || "Описание пока не загружено.")}</p></details><details><summary>Что пишут у конкурентов</summary><p class="caption">Обезличенные выдержки для справки. Сформулируйте собственное наблюдение; чужой текст не становится вашим опытом.</p>${p.guidance.signals?.positive?.length || p.guidance.signals?.critical?.length ? `<p class="caption">По словарным правилам в выдержках: положительные упоминания — ${esc(p.guidance.signals.positive.join(", ") || "не найдены")}; критические — ${esc(p.guidance.signals.critical.join(", ") || "не найдены")}. Проверьте смысл по исходному тексту.</p>` : ""}${p.competitors.flatMap((c) => (Number(c.confirmed) ? c.samples : []).map((t) => `<div class="sample"><p>${esc(t)}</p>${safeLink(c.url, sourceNames[c.source_id])}<small class="caption"> · ${date(c.last_success)}</small></div>`)).join("") || '<p class="muted">Выдержек ещё нет. Отсутствие текста не означает отсутствие отзывов.</p>'}</details><details><summary>Уже опубликовано у нас · ${p.reviews.length}${p.reviews.length === 20 ? "+" : ""}</summary>${p.reviews.map((r) => `<div class="sample"><small>${r.origin === "staff" ? "Материал команды" : "Покупатель"} · ${num(r.rating)} / 5</small><p>${esc(r.text)}</p></div>`).join("") || '<p class="muted">Опубликованных материалов пока нет.</p>'}</details><details><summary>Какие темы можно дополнить</summary><div class="tags">${p.guidance.missing.map((t) => `<span class="tag">${esc(t)}</span>`).join("")}</div><p class="caption">${esc(p.guidance.method)}</p><p class="caption">Подсказки основаны на локальных правилах. Платный ИИ не используется.</p></details></div></aside></div>`;
  countWords();
}
function writerData() {
  const form = $("#writer");
  const disabled = [...form.querySelectorAll(":disabled")];
  disabled.forEach((e) => (e.disabled = false));
  const f = new FormData(form);
  disabled.forEach((e) => (e.disabled = true));
  return {
    product_id: Number(state.product.id),
    draft_id: state.product.draft?.id || null,
    revision: Number(state.product.draft?.revision || 0),
    rating: f.get("rating") ? Number(f.get("rating")) : null,
    body: f.get("body"),
    sentiment: f.get("sentiment"),
    city: f.get("city"),
    experience_date: f.get("experience_date") || "",
  };
}
function countWords() {
  const n = (
    $("#body")?.value.match(/[\p{L}\p{N}]+(?:[’'-][\p{L}\p{N}]+)*/gu) || []
  ).length;
  if ($("#word-count")) $("#word-count").textContent = n + " слов";
}
async function save() {
  if (state.saving) return state.saving;
  const data = writerData();
  state.saving = request("drafts", data)
    .then((d) => {
      state.product.draft = d;
      const current = writerData();
      state.dirty =
        JSON.stringify({ ...current, revision: 0, draft_id: null }) !==
        JSON.stringify({ ...data, revision: 0, draft_id: null });
      $("#save-state").textContent =
        (state.dirty ? "Есть новые изменения. Сохранено в " : "Сохранено в ") +
        new Date().toLocaleTimeString("ru-RU", {
          hour: "2-digit",
          minute: "2-digit",
        });
      return d;
    })
    .finally(() => (state.saving = null));
  return state.saving;
}
async function send() {
  if (state.busy) return;
  state.busy = true;
  document
    .querySelectorAll(
      "#writer button,#writer input,#writer textarea,#writer select",
    )
    .forEach((b) => (b.disabled = true));
  try {
    if (state.saving) await state.saving;
    document
      .querySelectorAll("#writer input,#writer textarea,#writer select")
      .forEach((b) => (b.disabled = false));
    const pending = save();
    document
      .querySelectorAll("#writer input,#writer textarea,#writer select")
      .forEach((b) => (b.disabled = true));
    const d = await pending;
    await request("drafts/" + d.id + "/submit", {
      revision: Number(d.revision),
    });
    state.dirty = false;
    notice(
      "Материал сохранён в очереди отправки. После передачи он появится в админке магазина.",
    );
    state.product = null;
    await loadWork();
  } catch (e) {
    notice(e.message, true);
  } finally {
    state.busy = false;
    document
      .querySelectorAll(
        "#writer button,#writer input,#writer textarea,#writer select",
      )
      .forEach((b) => (b.disabled = false));
  }
}
async function renderDrafts() {
  const r = await request("drafts");
  if (state.view !== "drafts") return;
  main.innerHTML =
    intro(
      state.user.role === "author" ? "Мои материалы." : "Материалы команды.",
      "Черновики остаются в мастерской. Проверка и активация отправленных материалов — в админке магазина.",
    ) +
    `<div class="table-wrap"><table><thead><tr><th>Товар и текст</th><th>Автор</th><th>Статус</th><th>Обновлено</th></tr></thead><tbody>${r.items.map((d) => `<tr><td><strong>${esc(d.product_name)}</strong><details><summary>${num(d.rating)} / 5 · ${esc(depths[(d.body.match(/\S+/g) || []).length <= 30 ? "short" : (d.body.match(/\S+/g) || []).length <= 100 ? "normal" : "deep"])}</summary><p>${esc(d.body)}</p></details>${d.state === "historical" && Number(d.author_id) === Number(state.user.id) ? `<button class="small" data-copy="${d.id}">Подготовить к рабочему сайту</button>` : ""}${d.state === "draft" && Number(d.author_id) === Number(state.user.id) ? `<button class="small" data-edit="${d.product_id}">Продолжить</button>` : ""}</td><td>${esc(d.author_name)}</td><td><span class="status ${esc(d.state)}">${esc(labels[d.state])}</span>${d.last_error ? `<p class="error-text">${d.last_error === "extension_not_verified" ? "Ожидает настройки передачи в магазин. Текст сохранён." : d.last_error === "dispatch_conflict" ? "Передача приостановлена: конфликт записи. Нужна проверка администратора." : "Передача не завершилась; обработчик повторит попытку."}</p>` : ""}</td><td>${date(d.updated_at)}</td></tr>`).join("") || '<tr><td colspan="4">Материалов пока нет.</td></tr>'}</tbody></table></div><p class="caption">Статусы магазина обновляются фоновым обработчиком. Отключённая запись остаётся «На модерации»: отдельного статуса отказа в стандартной таблице ocStore нет.</p>`;
}
async function renderStats() {
  const r = await request("stats");
  if (state.view !== "stats") return;
  const counts = Object.fromEntries(
    r.states.map((x) => [x.state, Number(x.n)]),
  );
  main.innerHTML =
    intro(
      "Опыт превращается в пользу.",
      "Подготовленные материалы, глубина текстов и состояние публикации.",
    ) +
    `<div class="stats-grid">${[
      ["Черновики", counts.draft || 0],
      ["Ожидают отправки", counts.submitted || 0],
      ["На модерации", counts.moderation || 0],
      ["Опубликовано", counts.published || 0],
    ]
      .map(
        ([l, v]) =>
          `<div class="metric"><small>${l}</small><strong>${num(v)}</strong></div>`,
      )
      .join(
        "",
      )}</div><div class="panel panel-body"><h2>Глубина материалов</h2><p>${Object.entries(
      r.depths,
    )
      .map(([k, v]) => `${depths[k]}: <strong>${v}</strong>`)
      .join(
        " · ",
      )}</p><p class="caption">Средний объём — ${num(r.average_words)} слов. Объём не заменяет полезность материала.</p>${r.catalog ? `<p>Активных товаров: ${num(r.catalog.total)} · без отзывов покупателей: ${num(r.catalog.without_customer_reviews)} · без материалов команды: ${num(r.catalog.without_staff)}</p>` : ""}</div>${r.top ? `<h2 class="top-space">50 необработанных товаров по приоритету</h2><div class="table-wrap"><table><thead><tr><th>Товар</th><th>Приоритет</th></tr></thead><tbody>${r.top.map((p) => `<tr><td>${esc(p.name)}</td><td>${num(p.priority)}</td></tr>`).join("")}</tbody></table></div>` : ""}`;
  appendStats(r, counts);
}
function appendStats(r, counts) {
  const moods = {
    positive: "Положительные",
    neutral: "Нейтральные",
    mixed: "Смешанные",
    critical: "Критические",
  };
  main.insertAdjacentHTML(
    "beforeend",
    `<section class="panel panel-body top-space"><h2>Настроение материалов</h2><div class="tags">${r.sentiments.map((x) => `<span class="tag">${esc(moods[x.sentiment] || x.sentiment)} · ${num(x.n)}</span>`).join("") || "Материалов пока нет"}</div><p class="caption top-space">Удалено из магазина: ${num(counts.rejected || 0)}. Это наблюдаемый статус, а не отдельный подтверждённый отказ модератора.</p></section>${r.authors ? `<h2 class="top-space">Работа команды</h2><div class="table-wrap"><table><thead><tr><th>Автор</th><th>Подготовлено</th><th>Опубликовано</th></tr></thead><tbody>${r.authors.map((a) => `<tr><td>${esc(a.name)}</td><td>${num(a.total)}</td><td>${num(a.published)}</td></tr>`).join("")}</tbody></table></div>` : ""}${r.top_competitors ? `<h2 class="top-space">50 товаров по сигналу конкурентов</h2><div class="table-wrap"><table><thead><tr><th>Товар</th><th>Сигнал, 0–100</th></tr></thead><tbody>${r.top_competitors.map((p) => `<tr><td>${esc(p.name)}</td><td>${num(p.competitor_score)}</td></tr>`).join("") || '<tr><td colspan="2">Свежих подтверждённых значений пока нет.</td></tr>'}</tbody></table></div>` : ""}`,
  );
}

async function renderTeam() {
  const [users, settings] = await Promise.all([
    request("users"),
    request("settings"),
  ]);
  if (state.view !== "team") return;
  main.innerHTML =
    intro(
      "Своя мастерская для команды.",
      "Личные учётные записи отделены от аналитики и админки магазина.",
    ) +
    `<div class="admin-columns"><section class="panel panel-body"><h2>Пригласить сотрудника</h2><form method="post" id="new-user"><label>Имя автора<input name="name" required maxlength="40" placeholder="Таня"></label><label>Связь с магазином<input name="staff_role" required maxlength="30" placeholder="хозяйка"></label><label>Логин латиницей<input name="login" required minlength="3" maxlength="64" autocomplete="off"></label><label>Личный пароль · от 12 символов<input name="password" type="password" required minlength="12" maxlength="512" autocomplete="new-password"></label><label>Роль<select name="role"><option value="author">Автор · свои материалы</option><option value="moderator">Редактор · просмотр материалов команды</option><option value="admin">Администратор комбайна · команда и очередь</option></select></label><button class="primary">Создать учётную запись</button></form></section><section class="panel panel-body"><h2>Приоритет очереди</h2><form method="post" id="weights">${Object.entries(
      {
        competitor: "Отзывы у конкурентов",
        gap: "Недостаток материалов команды",
        sales: "Продажи за 90 дней",
        views: "Просмотры за 30 дней",
      },
    )
      .map(
        ([k, l]) =>
          `<label>${l}<input type="number" name="${k}" min="0" max="100" value="${settings.weights[k]}" required></label>`,
      )
      .join(
        "",
      )}<label>Пауза товара после отправки, дней<input type="number" name="cooldown_days" min="1" max="365" value="${settings.cooldown_days}" required></label><p class="caption">Просмотры пока не подключены. Веса доступных факторов пересчитываются автоматически.</p><button class="primary">Сохранить настройки</button></form><h3 class="top-space">Загрузка данных</h3><p class="caption">Каталог: ${date(state.health.shop_updated)}<br>Очередь: ${date(state.health.queue_updated)}<br>Передача в магазин: ${state.health.bridge_ready === "1" ? "подготовлена" : "ожидает проверки расширения"}${state.health.sync_error ? "<br>Последняя синхронизация завершилась ошибкой. Предыдущие данные сохранены." : ""}</p></section></div><h2 class="top-space">Команда</h2><div class="table-wrap"><table><thead><tr><th>Сотрудник</th><th>Логин</th><th>Роль</th><th>Доступ</th></tr></thead><tbody>${users.items.map((u) => `<tr><td>${esc(u.name)} · ${esc(u.staff_role)}</td><td>${esc(u.login)}</td><td>${esc({ author: "Автор", moderator: "Редактор", admin: "Администратор" }[u.role])}</td><td>${Number(u.id) !== Number(state.user.id) ? `<button class="small" data-user="${u.id}" data-active="${Number(u.active) ? "0" : "1"}">${Number(u.active) ? "Отключить" : "Включить"}</button>` : "Ваш профиль"}</td></tr>`).join("")}</tbody></table></div>`;
}
async function renderQueue() {
  const r = await request("queue");
  if (state.view !== "queue") return;
  main.innerHTML =
    intro(
      "У каждого товара — свой ход.",
      "Приоритет можно поправить с указанием причины. Назначение другому автору снимает администратор.",
    ) +
    `<div class="table-wrap"><table><thead><tr><th>Товар</th><th>Приоритет</th><th>В работе</th><th>Настройка</th></tr></thead><tbody>${r.items.map((p) => `<tr><td>${esc(p.name)}${p.boost_reason ? `<p class="caption">${esc(p.boost_reason)}</p>` : ""}</td><td>${num(p.priority)}</td><td>${esc(p.assigned_to || "Свободен")}</td><td><button class="small" data-adjust="${p.id}">Изменить</button></td></tr>`).join("")}</tbody></table></div>`;
}
document.addEventListener("submit", async (e) => {
  if (e.target.id === "login-form") {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    try {
      const r = await request("login", data);
      state.csrf = r.csrf;
      await start();
    } catch (err) {
      notice(err.message, true);
    }
  }
  if (e.target.id === "writer") {
    e.preventDefault();
    await send();
  }
  if (e.target.id === "new-user") {
    e.preventDefault();
    try {
      await request("users", Object.fromEntries(new FormData(e.target)));
      notice("Учётная запись создана. Передайте пароль сотруднику лично.");
      await renderTeam();
    } catch (err) {
      notice(err.message, true);
    }
  }
  if (e.target.id === "weights") {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    try {
      await request("settings", {
        weights: Object.fromEntries(
          ["competitor", "gap", "sales", "views"].map((k) => [
            k,
            Number(data[k]),
          ]),
        ),
        cooldown_days: Number(data.cooldown_days),
      });
      notice("Настройки сохранены, очередь пересчитана.");
    } catch (err) {
      notice(err.message, true);
    }
  }
});
document.addEventListener("click", async (e) => {
  const b = e.target.closest("button");
  if (!b) return;
  try {
    if (b.dataset.pane) {
      const w = document.querySelector(".workspace");
      w.dataset.pane = b.dataset.pane;
      w.querySelectorAll("[role=tab]").forEach((t) =>
        t.setAttribute("aria-selected", t === b ? "true" : "false"),
      );
    }
    if (b.dataset.view) await show(b.dataset.view);
    if (b.dataset.product) await loadProduct(Number(b.dataset.product));
    if (b.dataset.copy) {
      const d = await request("drafts/" + b.dataset.copy + "/copy", {});
      state.view = "write";
      state.assignments = (await request("assignments")).items;
      await loadProduct(Number(d.product_id));
    }
    if (b.dataset.edit) {
      state.view = "write";
      state.assignments = (await request("assignments")).items;
      await loadProduct(Number(b.dataset.edit));
    }
    if (b.id === "save") {
      await save();
      notice("Черновик сохранён.");
    }
    if (b.id === "skip") {
      if (state.dirty && !confirm("Пропустить товар без сохранения текста?"))
        return;
      await request("skip", { product_id: Number(state.product.id) });
      state.dirty = false;
      await loadWork();
    }
    if (b.id === "logout") {
      if (state.dirty && !confirm("Выйти без сохранения текста?")) return;
      await request("logout", {});
      state.dirty = false;
      location.reload();
    }
    if (b.id === "preview-button") {
      const d = writerData();
      $("#preview-content").innerHTML =
        `<div class="eyebrow">ПРЕДВАРИТЕЛЬНЫЙ ПРОСМОТР</div><h2>${esc(state.product.name)}</h2><strong>${esc(state.user.name)} · ${esc(state.user.staff_role)} Лавки</strong><p>${d.rating ? "★".repeat(d.rating) + " · " + d.rating + "/5" : "Оценка ещё не выбрана"}</p><p>${esc(d.body)}</p>${d.city ? `<small>Город автора: ${esc(d.city)}</small>` : ""}<p class="caption">Связь с магазином будет указана явно. Публикация — после модерации в ocStore.</p>`;
      $("#preview").showModal();
    }
    if (b.dataset.user) {
      await request("users/" + b.dataset.user, {
        active: b.dataset.active === "1",
      });
      await renderTeam();
    }
    if (b.dataset.adjust) {
      const reason = prompt("Причина изменения приоритета:");
      if (!reason) return;
      const value = prompt("Поправка приоритета от −100 до 100:", "0");
      if (value === null) return;
      const release = confirm(
        "Снять текущее назначение автора? «Отмена» сохранит назначение.",
      );
      await request("queue", {
        product_id: Number(b.dataset.adjust),
        boost: Number(value),
        reason,
        release,
      });
      await renderQueue();
    }
  } catch (err) {
    notice(err.message, true);
  }
});
document.addEventListener("input", (e) => {
  if (e.target.closest("#writer")) {
    state.dirty = true;
    if ($("#save-state"))
      $("#save-state").textContent = "Есть несохранённые изменения";
    countWords();
  }
});
document.addEventListener("change", async (e) => {
  if (e.target.id === "batch") {
    if (state.dirty && !confirm("Перейти к пакету без сохранения текста?"))
      return;
    try {
      await loadWork(Number(e.target.value));
    } catch (err) {
      notice(err.message, true);
    }
  }
});
document.addEventListener("keydown", (e) => {
  if (!state.product) return;
  const editing = /^(INPUT|TEXTAREA|SELECT)$/.test(
    document.activeElement?.tagName || "",
  );
  if (e.ctrlKey && e.key === "Enter") {
    e.preventDefault();
    send();
  }
  if (!editing && e.ctrlKey && e.key.toLowerCase() === "s") {
    e.preventDefault();
    save().catch((err) => notice(err.message, true));
  }
  if (!editing && e.altKey && ["ArrowRight", "ArrowLeft"].includes(e.key)) {
    e.preventDefault();
    const index = state.assignments.findIndex((p) => p.id === state.product.id),
      next = state.assignments[index + (e.key === "ArrowRight" ? 1 : -1)];
    if (next) loadProduct(next.id).catch((err) => notice(err.message, true));
  }
});
window.addEventListener("beforeunload", (e) => {
  if (state.dirty) {
    e.preventDefault();
    e.returnValue = "";
  }
});
start();
