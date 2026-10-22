# Свои цены из каталога ocStore

Подготовлен отдельный импорт публичных цен каталога. Он не меняет цены магазина,
права MySQL, `source.json`, заказы, CRM, сессии или таблицы мониторинга. Текущий
этап — проверка формата отчёта; подключение каталога не включается автоматически.
Сначала пользователь переведёт существующий источник на тестовую БД и отдельно
подготовит права чтения. Пароль в чат не передавать и заново не настраивать.

## Что получает отчёт

Снимок `own-catalog.json` хранится в существующем закрытом каталоге с правами 600.
В нём только выбранные активные товары «Лавки специй» с числовым `product_id` в
существующих ссылках мониторинга. Весь ассортимент и данные покупателей не читаются.
Снимок отделён от `analytics.sqlite3` и `competitors.sqlite3`.

Успешный результат имеет `state: ok`, `last_success`, `checked_at`, `products`.
Каждый товар содержит `target_id`, `product_id`/`source_product_id`, название,
модель, ссылку и `offers`. У предложения есть точный вес `grams`, название фасовки,
цена строкой, UAH, наличие, время и `provenance`: ID товара, опции и значения,
база цены, поправка опции и вид базы (`base`, `special`, `discount_quantity_1`).
Неизвестная фасовка или неподдерживаемая формула дают предупреждение и не создают
цену. Свободная строка фасовок из списка продаж не является доказательством веса.

При ошибке прошлый успешный снимок сохраняется; `own-catalog-status.json` сообщает
`state: error` и безопасный код без текста драйвера. Такой результат не участвует
в определении лидера. Успешный снимок старше 48 часов получает `state: stale`.

## Модель цены и границы

Поддерживается стандартная модель ocStore 3 для гостевой группы магазина 0:

1. Текущая базовая цена товара; активная скидка на одну упаковку, если она есть.
2. Активная акция для той же гостевой группы с приоритетом и ценой, как в ocStore.
3. Поправка `+`/`-` **конкретного** значения опции фасовки. Нулевая база допустима,
   если итоговая цена выбранной упаковки положительна.

Оптовые скидки от количества больше 1 и цены других групп не используются.
Берётся одна группа фасовок типа select/radio/image. Дополнительные обязательные
опции, несколько групп фасовок, нестандартные префиксы и неизвестный вес исключают
цену. Если опций нет, вес можно взять из однозначного свежего названия товара в
`product_description`; старые подписи из аналитики для этого не используются.
Миллиграммы действующего вещества и миллилитры не переводятся в граммы.

Проверяются статус товара, принадлежность магазину 0, дата доступности, минимальное
количество и остаток выбранной опции. Отсутствие товара не считается ошибкой чтения.
Поддерживается UAH с действующим курсом 1 и двумя десятичными знаками. Если магазин
включает налоги и у товара есть налоговый класс, предложение исключается с
`tax_unresolved`: географию налога угадывать нельзя. Сторонние модули цен,
персональные скидки, купоны и лояльность потребуют отдельной сверки с тестовой
витриной. До такой сверки импорт нельзя считать подтверждением всех её модификаций.

Все запросы к источнику — SELECT; для согласованности используются read-only
транзакция и две одинаковые сортированные выборки. Это также обнаруживает обычное
изменение MyISAM между чтениями. Изменившийся набор не заменяет прошлый снимок;
две равные выборки не являются транзакционной гарантией MyISAM. Бюджет проверяется
между запросами (30 секунд); уже начатый запрос ограничен таймаутом MySQL-клиента.

## Какие права подготовить позже

Существующие шесть SELECT на заказы сохранить. Дополнительно нужны SELECT только
на следующие объекты каталога с текущим префиксом `oc_`:

- `oc_product`, `oc_product_to_store`, `oc_product_description`;
- `oc_product_option`, `oc_product_option_value`, `oc_option`,
  `oc_option_value_description`;
- `oc_product_discount`, `oc_product_special`;
- `oc_currency`, `oc_language`;
- ограниченное представление **`oc_analytics_catalog_settings`**.

Не выдавать пользователю аналитики SELECT на всю `oc_setting`: в ней могут быть
секреты других модулей. Код обращается только к фиксированному представлению с
четырьмя разрешёнными ключами. Представление создаёт администратор БД с правами
чтения `oc_setting`; приложение не выполняет DDL.

Следующий SQL — шаблон для администратора **будущей тестовой БД**, не команда
обновления текущего кабинета. `TEST_DATABASE` заменить на выбранную тестовую БД;
`EXISTING_HOST` — на host уже существующей учётной записи `lavkasp_analytics`.
Не создавать другого пользователя, не менять пароль и не отзывать старые права.

```sql
CREATE OR REPLACE SQL SECURITY DEFINER VIEW TEST_DATABASE.oc_analytics_catalog_settings AS
SELECT store_id, `key`, `value`, serialized
FROM TEST_DATABASE.oc_setting
WHERE store_id = 0
  AND `key` IN ('config_currency', 'config_language', 'config_customer_group_id', 'config_tax');

GRANT SELECT ON TEST_DATABASE.oc_product TO 'lavkasp_analytics'@'EXISTING_HOST';
GRANT SELECT ON TEST_DATABASE.oc_product_to_store TO 'lavkasp_analytics'@'EXISTING_HOST';
GRANT SELECT ON TEST_DATABASE.oc_product_description TO 'lavkasp_analytics'@'EXISTING_HOST';
GRANT SELECT ON TEST_DATABASE.oc_product_option TO 'lavkasp_analytics'@'EXISTING_HOST';
GRANT SELECT ON TEST_DATABASE.oc_product_option_value TO 'lavkasp_analytics'@'EXISTING_HOST';
GRANT SELECT ON TEST_DATABASE.oc_option TO 'lavkasp_analytics'@'EXISTING_HOST';
GRANT SELECT ON TEST_DATABASE.oc_option_value_description TO 'lavkasp_analytics'@'EXISTING_HOST';
GRANT SELECT ON TEST_DATABASE.oc_product_discount TO 'lavkasp_analytics'@'EXISTING_HOST';
GRANT SELECT ON TEST_DATABASE.oc_product_special TO 'lavkasp_analytics'@'EXISTING_HOST';
GRANT SELECT ON TEST_DATABASE.oc_currency TO 'lavkasp_analytics'@'EXISTING_HOST';
GRANT SELECT ON TEST_DATABASE.oc_language TO 'lavkasp_analytics'@'EXISTING_HOST';
GRANT SELECT ON TEST_DATABASE.oc_analytics_catalog_settings TO 'lavkasp_analytics'@'EXISTING_HOST';
```

Полный список фиксированных полей и объектов можно вывести без подключения к БД
и без чтения настроек соединения:

```bash
/usr/local/php82/bin/php /home/lavkasp/lavka-speciy.com/analytics-app/spice-analytics/php/bin/sync_competitor_catalog.php --requirements
```

Если хостинг ограничивает создание представлений, не расширять доступ к секретам
ради обхода. Подготовить ограниченное представление через администратора хостинга
или отдельно согласовать узкую конфигурацию каталога.

## Первое включение после подключения тестовой БД

Только после подготовки тестового источника и SELECT выполнить один раз в WebSSH:

```bash
/usr/local/php82/bin/php /home/lavkasp/lavka-speciy.com/analytics-app/spice-analytics/php/bin/sync_competitor_catalog.php --data /home/lavkasp/lavka-speciy.com/analytics-private
```

Команда использует уже существующий закрытый `source.json`; параметры соединения
и пароль не печатает. Выводит только статус, время и количество товаров/фасовок.
Неуспех сохраняет прежние цены и выдаёт код вроде `catalog_select_required`,
`catalog_read_failed`, `catalog_settings_missing`, `catalog_currency_unsupported`.

До первого успешного ручного импорта фоновая функция `sync_due` не открывает
`source.json` и не подключается к MySQL. После успешного импорта существующий runner
может обновлять снимок не чаще раза в сутки; новая задача cron не нужна. Это
включает только чтение. Запись и массовый пересчёт цен магазина здесь не реализованы.
