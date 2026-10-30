# Общие правила проектов «Лавка»

## Размещение на хостинге — сначала прочитать

По поручению владельца от 30.10.2026 текущий этап — подготовка общего переноса
каталогов. Перед изменением путей, установкой, обновлением или выдачей SSH/cron
команд прочитать [HOSTING_PATHS.md](HOSTING_PATHS.md),
[hosting-paths.json](hosting-paths.json) и [HOSTING_MIGRATION.md](HOSTING_MIGRATION.md).
Новые задачи владельца имеют приоритет; самостоятельное развитие функций
не включать в работу по переносу.

- Это единый контракт аналитики, Review Factory и витрины, версия
  `2026-10-30.2`. Каноническая копия — корень ветки `master`; копии в рабочих
  ветках должны совпадать. Изменение контракта распространять отдельно от кода
  приложений; не сливать целиком несовместимые ветки ради документации.
- SSH-отчёт 30.10.2026 подтвердил код модулей в `apps/lavka-modules` и
  завершённый перенос клона витрины в `apps/lavka-frontend` (`completed`).
  Полная последняя инвентаризация данных была до переноса (`legacy`);
  работу кабинетов и новые cron проверять отдельно. Не считать публикацию
  документа доказательством работоспособности всех модулей.
- Подготовить поддержку целевых путей и перечень зависимостей своего проекта.
  До согласованного переключения сохранять работающие старые пути. Не запускать
  установку с новым пустым `--data` и не отключать защитные проверки путей.
- Ручные объединения/отклонения клиентов, связи конкурентов, CRM, черновики,
  пользователи, пароли и история — постоянные данные. Переносить существующие
  данные, без повторной инициализации, перематчивания и глобальных замен в БД.
- Перенос каталогов TEST не является миграцией TEST → production. Сохраняются
  домены, MySQL, ID, права доступа и границы сред. `www`, `test`, `analytics`,
  `lavka_storage`, `test_storage` на первом этапе не перемещаются.
- Аналитика и Review Factory используют общий Git-клон
  `/home/lavkasp/lavka-speciy.com/apps/lavka-modules`. Отдельный клон витрины
  теперь находится в `/home/lavkasp/lavka-speciy.com/apps/lavka-frontend`.
  Старые `analytics-app` и `/home/lavkasp/lavka-frontend-work` — исторические
  пути и адреса отката; не создавать там второй экземпляр и не делать симлинки.
  `REPO` инструментов витрины определяется по `__file__`.
- Инструкции, конфиги, launchers, cron, фоновые обработчики, демо-мост и откат
  должны получить новые пути вместе. Не маскировать старые пути симлинками.
- Каждый проект передаёт отчёт готовности по форме из HOSTING_MIGRATION.md.
  Не отмечать чужой проект или физический перенос завершённым без проверки.

## Границы работы и передачи

- Правки магазина выполняются только на `test.lavka-speciy.com` / `lavkasp_test`.
  Рабочая витрина — существующий ocStore; новое приложение вместо неё не создавать.
- Передача изменений витрины на production — проверенные файлы/diff и ручная
  проверка Игорем. К аналитике это требование не относится: её обычный процесс —
  Git и проверенные SSH-команды для существующего тестового модуля.
- Секреты, конфиги подключений, частные SQLite/MySQL, клиентские данные,
  резервные копии и кэши не коммитить и не включать в выпускные архивы.
- Готовые инструкции и наборы передачи публиковать через этот приватный GitHub.
  Пользователю недоступны ссылки на вложения из рабочего каталога чата.
- PHP 8.2 CLI подтверждён для модулей. Версию веб-PHP каждого домена проверять
  отдельно; перенос каталогов не является поводом менять PHP магазина.

# Project collaboration preferences

- Deliver generated files and handoff bundles through this GitHub repository.
  The user cannot download workspace attachments from chat; do not offer those links.
- Keep reviewed handoff artifacts under `handover/releases/`, with a README and
  checksum. Commit and push them before providing GitHub links to the user.
- Use ordinary ZIP archives without a password unless the user asks otherwise.
- Keep the repository private. Never include connection configs, passwords,
  private keys, customer data, database dumps or runtime cache in release bundles.
- Shop changes remain restricted to the test installation. Igor performs the
  reviewed production transfer manually; preparing a bundle does not deploy it.
