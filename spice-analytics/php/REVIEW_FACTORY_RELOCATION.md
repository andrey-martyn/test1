# Поправочные инструкции для проекта Review Factory

Сначала прочитать единый корневой контракт
[HOSTING_PATHS.md](../../HOSTING_PATHS.md) и общий порядок
[HOSTING_MIGRATION.md](../../HOSTING_MIGRATION.md), версия `2026-10-30.1`.
Он также находится в рабочих ветках витрины. Здесь — детали Review Factory.

Подготовленная общая команда `ops/relocate_hosting.py` в `analytics-demo`
выполняет точечную перенастройку существующей установки и её демо-моста,
сохраняя обе SQLite и все постоянные файлы. Запуск и откат — в корневом
[HOSTING_MIGRATION.md](../../HOSTING_MIGRATION.md#готовая-команда-переноса).
Она не вызывает повторный setup/deploy и не устанавливает отсутствующий демо-модуль.

Это согласованный контракт путей для подготовки уборки хостинга. Физический
перенос ещё не выполнен. Сейчас работают старые пути. Подготовить поддержку новых
путей и команду перенастройки; переключать работающий модуль вместе с аналитикой,
после остановки записей и фоновых задач. Повторная установка не требуется.

## Точные пути

Все пути ниже относительно `/home/lavkasp/lavka-speciy.com/`:

| Назначение | Сейчас | После переноса |
| --- | --- | --- |
| Весь Git-клон | `analytics-app/` | `apps/lavka-modules/` |
| Код Review Factory | `analytics-app/review-factory/php/` | `apps/lavka-modules/review-factory/php/` |
| Закрытые данные Review Factory | `review-factory-private-test/` | `private/review-factory/test/` |
| Данные тестовой аналитики, только чтение | `analytics-private-test/` | `private/analytics/test/` |
| Предыдущая база аналитики | `analytics-private/` | `private/analytics/previous/` |

Git-клон переносится целиком с `.git`, `spice-analytics` и `review-factory`.
Не создавать отдельный пустой клон или новую базу взамен имеющейся.

Следующие значения сохраняются:

```text
environment = test
shop_root = /home/lavkasp/lavka-speciy.com/test
webroot = /home/lavkasp/lavka-speciy.com/test/review-workshop
public_url = https://test.lavka-speciy.com/review-workshop
public_roots = существующий список из instance.json:
  /home/lavkasp/lavka-speciy.com/test
  /home/lavkasp/lavka-speciy.com/www
  /home/lavkasp/lavka-speciy.com/analytics
```

## Что исправить в проекте

1. Подготовить ограниченную команду перенастройки существующей установки, с
   предварительным планом и проверкой ожидаемых старых путей. Обычный `deploy`
   сейчас отказывается работать с занятой публичной папкой, поэтому повторный
   `setup/deploy` не использовать как способ переноса.
2. Сохранять весь существующий закрытый каталог, включая `factory.sqlite3`,
   существующую `synthetic-reviews.sqlite3`, `instance.json`, пользователей, хеши паролей, роли,
   черновики, утверждения, очередь публикаций, импортированные отзывы,
   историю действий и все остальные существующие данные. В синтетической базе
   сохраняются в том числе решения `demo_decisions` и аудит `demo_events`.
   Это перенос TEST между каталогами, а не production-миграция, исключающая
   синтетические записи. Перенос выполнять
   с согласованным снимком SQLite и учётом WAL. После него сверить состав данных,
   а не только наличие файла.
3. В перенесённом `private/review-factory/test/instance.json` заменить только
   `analytics_data` на `/home/lavkasp/lavka-speciy.com/private/analytics/test`.
   Сохранить все остальные ключи, включая язык и статусы заказов.
   Если версия проекта содержит дополнительные абсолютные пути, инвентаризировать
   и перенастроить их явно, не выполнять глобальную замену по всей БД.
4. Атомарно переписать только проверенный существующий launcher
   `test/review-workshop/index.php`. Он должен подключать
   `/home/lavkasp/lavka-speciy.com/apps/lavka-modules/review-factory/php/src/App.php`
   и передавать в `ReviewFactory\run()` каталог
   `/home/lavkasp/lavka-speciy.com/private/review-factory/test`.
   Публичный URL сохраняется. До записи сверить старое содержимое и сохранить
   его закрытую копию для отката; посторонний launcher не заменять.
   Если установлен TEST демо-модуль, также обновить
   `test/system/library/review_factory_demo_config.php`, поля `runtime` и `data`.
   После перенастройки существующей конфигурации штатный `demo-install` может
   обновить собственные файлы/пути, сохранив решения модерации. Не устанавливать
   демо-модуль как побочный эффект, если его ещё нет в этой установке.
5. Во всех существующих cron, инструкциях и настройках проекта заменить путь
   к `console.php` и `--data`. Сохранить текущие расписания, лимиты и операции.
   Не добавлять публикации/рассылки ради переноса. На полученном скриншоте панели
   отдельных задач Review Factory нет; наличие и способ их запуска уточняет
   этот проект перед переключением.
6. Собирать подтверждённые ссылки напрямую из перенесённой
   `private/analytics/test/competitors.sqlite3` в режиме только чтения. Аналитику
   и её схемы не изменять. Отдельный JSON больше не является рабочим источником:
   пользователь уже забрал `review-harvester-private/confirmed-product-pages.json`
   и разрешил удалить прежнюю экспортную папку. Не создавать её заново.
7. До физического переноса использовать старые пути. Не запускать команды,
   способные создать БД, с новым `--data`, пока существующая база не перенесена
   и не проверена. Поддержать откат на прежние пути, без отката данных к старому
   состоянию и без потери сделанного после переключения.

## Команды после завершённого переноса

Проверка состояния:

```bash
/usr/local/php82/bin/php /home/lavkasp/lavka-speciy.com/apps/lavka-modules/review-factory/php/bin/console.php status \
  --data /home/lavkasp/lavka-speciy.com/private/review-factory/test
```

Шаблоны существующих задач (расписание остаётся прежним):

```bash
/usr/local/php82/bin/php /home/lavkasp/lavka-speciy.com/apps/lavka-modules/review-factory/php/bin/console.php sync --data /home/lavkasp/lavka-speciy.com/private/review-factory/test
/usr/local/php82/bin/php /home/lavkasp/lavka-speciy.com/apps/lavka-modules/review-factory/php/bin/console.php collect --data /home/lavkasp/lavka-speciy.com/private/review-factory/test --limit 12 --seconds 45
```

`dispatch` переносится только если он уже настроен и нужен владельцу, с его
существующим расписанием. Для проверки переноса не запускать публикацию отзывов.

Проверить вход Тани, роли, товары, черновики и историю, импорт подтверждённых
ссылок и работу существующих задач. Отдельно подтвердить, что MySQL и рабочий
магазин не изменились. До этих проверок сохранять резервные копии и возможность
отката на прежнее размещение.
