"use strict";
const replyState = { filter: "unanswered", search: "", page: 1, review: null, timer: null };
function replyNativeDate(value) {
  if (!/^\d{4}-\d{2}-\d{2} /.test(value || "") || value.startsWith("0000")) return "Дата не указана";
  return new Date(value.replace(" ", "T") + "Z").toLocaleString("ru-RU", {
    timeZone: "UTC",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}
const replyStatuses = {
  draft: "Черновик",
  queued: "Ожидает сохранения",
  applied: "Ответ сохранён",
  conflict: "Нужна проверка",
};
function replyError(code) {
  return (
    {
      reply_conflict: "Отзыв или ответ изменился в админке. Перечитайте актуальную карточку.",
      reply_review_removed: "Отзыв удалён из магазина.",
      reply_database_mismatch: "Источник изменился. Ответ не перенесён в другую базу.",
      reply_author_disabled: "Доступ автора ответа изменился.",
      reply_text_invalid:
        "Магазин не принимает часть символов ответа. Текст сохранён в истории; подготовьте исправленный вариант.",
      reply_shop_unavailable: "Магазин временно недоступен. Ответ сохранён в очереди.",
    }[code] || "Сохранение ещё не завершено."
  );
}
async function renderReplies() {
  clearTimeout(replyState.timer);
  replyState.review = null;
  const sequence = ++state.navigation;
  const data = await request("replies/search", {
    filter: replyState.filter,
    search: replyState.search,
    page: replyState.page,
  });
  if (state.view !== "replies" || sequence !== state.navigation) return;
  replyState.page = data.page;
  main.innerHTML =
    intro(
      "Отвечаем от имени магазина.",
      "Здесь — существующие отзывы покупателей. Вы готовите ответ магазина, оценка и текст покупателя сохраняются.",
    ) +
    `<div class="reply-toolbar"><form id="reply-search" method="post"><label class="sr-only" for="reply-query">Поиск по товару, автору или тексту</label><input id="reply-query" name="search" maxlength="200" value="${esc(replyState.search)}" placeholder="Товар, автор или слова из отзыва"><button>Найти</button></form><label>Показать<select id="reply-filter">${Object.entries(
      { unanswered: "Без ответа", answered: "С ответом", all: "Все отзывы" },
    )
      .map(([k, v]) => `<option value="${k}" ${replyState.filter === k ? "selected" : ""}>${v}</option>`)
      .join(
        "",
      )}</select></label><button id="reply-history">Мои ответы</button></div><p class="caption">Найдено: ${num(data.total)} · отзывы загружены ${date(data.updated_at)}</p><div class="reply-list">${data.items.map((r) => `<article class="panel reply-card"><div><div class="eyebrow">${esc(r.product_name)}</div><h2>${esc(r.author || "Покупатель")} <span class="reply-rating">${num(r.rating)} / 5</span></h2><p class="reply-excerpt">${esc(r.excerpt)}</p><p class="caption">${replyNativeDate(r.published_at)} · ${Number(r.status) === 1 ? "Опубликован" : "На модерации"}${r.shop_reply ? " · Есть ответ магазина" : ""}${r.draft_owner ? " · В работе: " + esc(r.draft_owner) : ""}</p></div><button class="primary" data-reply-open="${r.id}">${r.shop_reply ? "Открыть ответ" : "Ответить"}</button></article>`).join("") || '<div class="empty"><h2>В этом списке пока нет отзывов</h2><p>Выберите другой отбор. Если загрузка ещё не запускалась, администратор сначала обновляет отзывы из TEST-магазина.</p></div>'}</div><div class="reply-pagination"><button data-reply-page="${Math.max(1, data.page - 1)}" ${data.page <= 1 ? "disabled" : ""}>← Назад</button><span>Страница ${data.page} из ${Math.max(1, data.pages)}</span><button data-reply-page="${data.page + 1}" ${data.page >= data.pages ? "disabled" : ""}>Дальше →</button></div>`;
}
async function openReply(id) {
  if (state.saving || state.busy) return;
  if (state.dirty && !confirm("Есть несохранённый ответ. Перейти без сохранения?")) return;
  state.dirty = false;
  const sequence = ++state.navigation;
  const r = await request("replies/" + id);
  if (state.view !== "replies" || sequence !== state.navigation) return;
  replyState.review = r;
  paintReply();
}
function paintReply() {
  const r = replyState.review,
    d = r.draft,
    ours = d && Number(d.user_id) === Number(state.user.id),
    locked = d && (!ours || d.state === "queued");
  main.innerHTML =
    intro("Ответ магазина.", "Отвечайте на вопросы и замечания покупателей от имени магазина.") +
    `<div class="reply-back"><button id="reply-back">← К отзывам</button><button id="reply-history">Мои ответы</button></div><div class="reply-workspace"><section class="panel panel-body"><div class="eyebrow">ОТЗЫВ ПОКУПАТЕЛЯ · № ${num(r.id)}</div><h2>${esc(r.product_name)}</h2><p class="caption">${safeLink((state.environment==="production"?"https://lavka-speciy.com":"https://test.lavka-speciy.com")+"/index.php?route=product/product&product_id="+Number(r.product_id),"Открыть карточку товара")}</p><div class="reply-review-meta"><strong>${esc(r.author || "Покупатель")}</strong><span class="reply-rating">${num(r.rating)} / 5</span><span class="caption">${replyNativeDate(r.published_at)} · ${Number(r.status) === 1 ? "Опубликован" : "На модерации"}</span></div><p class="reply-original">${esc(r.body)}</p>${r.plus_text ? `<h3>Достоинства</h3><p class="reply-original">${esc(r.plus_text)}</p>` : ""}${r.minus_text ? `<h3>Недостатки</h3><p class="reply-original">${esc(r.minus_text)}</p>` : ""}${r.shop_reply ? `<div class="reply-existing"><h3>Ответ, загруженный из магазина</h3><p>${esc(r.reply_plain)}</p></div>` : '<p class="caption">Ответа магазина пока нет.</p>'}</section><section class="panel panel-body"><div class="author-note"><strong>${esc(state.user.name)} · ${esc(state.user.staff_role)} Лавки</strong>Вы отвечаете от имени магазина. Автор и звёзды отзыва покупателя не меняются.</div>${locked ? `<p class="reply-banner">${ours ? "Ответ ожидает сохранения в магазине. Состояние обновляется автоматически." : "Ответ готовит " + esc(d.owner) + "."}</p>` : ""}<form id="reply-editor" method="post"><label for="reply-body">Ответ покупателю</label><textarea id="reply-body" maxlength="8000" placeholder="Поблагодарите за отзыв и ответьте на вопрос покупателя…" ${locked ? "disabled" : ""}>${esc(ours ? d.body : r.reply_plain)}</textarea><p class="caption">Обычный текст, без HTML. После сохранения ответ появится в обычном блоке ответа магазина.</p><div class="writer-footer"><span id="reply-save-state">${d ? esc(replyStatuses[d.state]) : "Черновик ещё не сохранён"}</span><span></span></div><div class="actions"><button type="button" id="reply-save" ${locked ? "disabled" : ""}>Сохранить черновик</button><button type="submit" class="primary" ${locked ? "disabled" : ""}>Сохранить ответ ${state.environment === "test" ? "на TEST" : "в магазине"} →</button></div></form>${ours && d.state === "draft" ? '<button class="quiet small" id="reply-restart">Перечитать отзыв и начать новый черновик</button>' : ""}<div class="reply-recent"><h3>Предыдущие попытки</h3>${
      r.recent
        .filter((x) => x.id !== d?.id)
        .map(
          (x) =>
            `<details><summary>${esc(replyStatuses[x.state] || x.state)} · ${date(x.updated_at)}</summary><p>${esc(x.body)}</p>${x.error ? `<p class="error-text">${esc(replyError(x.error))}</p>` : ""}</details>`,
        )
        .join("") || '<p class="caption">Ещё не было отправленных ответов.</p>'
    }</div></section></div>`;
  clearTimeout(replyState.timer);
  if (ours && d.state === "queued")
    replyState.timer = setTimeout(() => pollReply(Number(r.id), d.id), 5000);
}
async function pollReply(id, draftId) {
  if (state.view !== "replies" || !replyState.review || Number(replyState.review.id) !== id) return;
  try {
    const r = await request("replies/" + id);
    if (state.view !== "replies" || !replyState.review || Number(replyState.review.id) !== id) return;
    if (state.dirty || state.saving || state.busy) {
      replyState.timer = setTimeout(() => pollReply(id, draftId), 5000);
      return;
    }
    replyState.review = r;
    paintReply();
    const recent = r.recent.find((x) => x.id === draftId);
    if (recent?.state === "applied") notice("Ответ сохранён в магазине.");
    if (recent?.state === "conflict") notice(replyError(recent.error), true);
  } catch (e) {
    notice(e.message, true);
  }
}
async function saveReplyDraft() {
  if (state.saving) return state.saving;
  const r = replyState.review,
    d = r.draft;
  const body = $("#reply-body").value;
  state.saving = request("reply-drafts", {
    review_id: Number(r.id),
    draft_id: d?.id || null,
    revision: Number(d?.revision || 0),
    body,
  })
    .then((saved) => {
      r.draft = { ...saved, owner: state.user.name };
      state.dirty = $("#reply-body").value !== body;
      $("#reply-save-state").textContent = state.dirty ? "Есть новые изменения" : "Черновик сохранён";
      return saved;
    })
    .finally(() => (state.saving = null));
  return state.saving;
}
async function sendReply() {
  if (state.busy) return;
  state.busy = true;
  try {
    if (state.saving) await state.saving;
    const pending = saveReplyDraft();
    document.querySelectorAll("#reply-editor button,#reply-body").forEach((b) => (b.disabled = true));
    const d = await pending;
    const result = await request("reply-drafts/" + d.id + "/submit", { revision: Number(d.revision) });
    state.dirty = false;
    replyState.review.draft = { ...result, owner: state.user.name };
    paintReply();
    notice(
      result.state === "applied"
        ? "Такой ответ уже сохранён."
        : "Ответ поставлен на сохранение в магазин.",
    );
  } catch (e) {
    notice(e.message, true);
  } finally {
    state.busy = false;
    const d = replyState.review?.draft;
    if (d?.state !== "queued")
      document.querySelectorAll("#reply-editor button,#reply-body").forEach((b) => (b.disabled = false));
  }
}
async function replyHistory() {
  if (state.saving || state.busy) return;
  if (state.dirty && !confirm("Перейти без сохранения ответа?")) return;
  state.dirty = false;
  replyState.review = null;
  clearTimeout(replyState.timer);
  const data = await request("reply-history");
  if (state.view !== "replies") return;
  main.innerHTML =
    intro("Мои ответы магазина.", "Черновики, отправка и результат сохранения в UniShop2.") +
    `<button id="reply-back">← К отзывам</button><div class="table-wrap top-space"><table><thead><tr><th>Товар и ответ</th><th>Состояние</th><th>Обновлено</th></tr></thead><tbody>${data.items.map((r) => `<tr><td><strong>${esc(r.product_name)}</strong><p>${esc(r.body)}</p>${r.plus_text ? `<h3>Достоинства</h3><p class="reply-original">${esc(r.plus_text)}</p>` : ""}${r.minus_text ? `<h3>Недостатки</h3><p class="reply-original">${esc(r.minus_text)}</p>` : ""}${Number(r.available) ? `<button class="small" data-reply-open="${r.review_id}">Открыть отзыв № ${num(r.review_id)}</button>` : ""}</td><td><span class="status">${esc(replyStatuses[r.state] || r.state)}</span>${r.error ? `<p class="error-text">${esc(replyError(r.error))}</p>` : ""}</td><td>${date(r.updated_at)}</td></tr>`).join("") || '<tr><td colspan="3">Пока нет ответов.</td></tr>'}</tbody></table></div>`;
}
document.addEventListener("submit", async (e) => {
  if (e.target.id === "reply-search") {
    e.preventDefault();
    replyState.search = $("#reply-query").value;
    replyState.page = 1;
    try {
      await renderReplies();
    } catch (err) {
      notice(err.message, true);
    }
  }
  if (e.target.id === "reply-editor") {
    e.preventDefault();
    await sendReply();
  }
});
document.addEventListener("input", (e) => {
  if (e.target.id === "reply-body") {
    state.dirty = true;
    $("#reply-save-state").textContent = "Есть несохранённые изменения";
  }
});
document.addEventListener("change", async (e) => {
  if (e.target.id === "reply-filter") {
    replyState.filter = e.target.value;
    replyState.page = 1;
    try {
      await renderReplies();
    } catch (err) {
      notice(err.message, true);
    }
  }
});
document.addEventListener("click", async (e) => {
  const b = e.target.closest("button");
  if (!b) return;
  try {
    if (b.dataset.replyOpen) await openReply(Number(b.dataset.replyOpen));
    if (b.dataset.replyPage) {
      replyState.page = Number(b.dataset.replyPage);
      await renderReplies();
    }
    if (b.id === "reply-save") {
      await saveReplyDraft();
      notice("Черновик ответа сохранён.");
    }
    if (b.id === "reply-back") {
      if (state.dirty && !confirm("Перейти без сохранения ответа?")) return;
      state.dirty = false;
      await renderReplies();
    }
    if (b.id === "reply-history") await replyHistory();
    if (b.id === "reply-restart") {
      if (
        !confirm("Сохранённый текст останется в истории. Начать новый черновик по актуальной карточке?")
      )
        return;
      await request("replies/" + replyState.review.id + "/restart", {});
      state.dirty = false;
      await openReply(Number(replyState.review.id));
    }
  } catch (err) {
    notice(err.message, true);
  }
});
document.addEventListener("keydown", (e) => {
  if (state.view === "replies" && $("#reply-editor") && e.ctrlKey && e.key === "Enter") {
    e.preventDefault();
    sendReply();
  }
});
