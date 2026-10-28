<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    exit();
}
require_once dirname(__DIR__) . "/src/Core.php";
require_once dirname(__DIR__) . "/src/Replies.php";
$dir = $argv[1] ?? "";
if (!$dir || is_dir($dir)) {
    throw new RuntimeException("Use a new private directory");
}
mkdir($dir, 0700, true);
$db = \ReviewFactory\database($dir);
$password = getenv("RF_TEST_PASSWORD");
if (!$password) {
    throw new RuntimeException("Synthetic test password missing");
}
\ReviewFactory\createUser($db, [
    "login" => "tanya-demo",
    "work_mode" => "author",
    "name" => "Таня · демо",
    "staff_role" => "хозяйка",
    "role" => "admin",
    "password" => $password,
]);
\ReviewFactory\createUser($db, [
    "login" => "author-demo",
    "name" => "Автор · демо",
    "staff_role" => "сотрудник",
    "role" => "author",
    "password" => $password,
]);
\ReviewFactory\createUser($db, [
    "login" => "admin-review",
    "name" => "Таня · демо",
    "staff_role" => "хозяйка",
    "role" => "admin",
    "password" => $password,
]);
$products = [
    [
        "Корица цейлонская молотая",
        "Пряности",
        "Настоящая цейлонская корица. Тонкий, тёплый аромат с цветочными нотами. Подходит для выпечки, каш и напитков. Состав: кора корицы цейлонской, молотая. Хранить в плотно закрытой упаковке в сухом прохладном месте.",
    ],
    ["Кардамон зелёный", "Пряности", "Целые плоды зелёного кардамона."],
    ["Куркума молотая", "Пряности", "Молотая куркума без добавок."],
    ["Какао премиум 22%", "Для кондитеров", "Алкализованный какао-порошок."],
    ["Перец чёрный горошком", "Пряности", "Цельный чёрный перец."],
    ["Паприка копчёная", "Пряности", "Молотая копчёная паприка."],
];
foreach ($products as $i => $p) {
    $id = $i + 1;
    \ReviewFactory\execute(
        $db,
        "INSERT INTO products(id,model,name,category,description,url,stock,customer_count,customer_rating,sales90,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
        [
            $id,
            "DEMO-" . $id,
            $p[0],
            $p[1],
            $p[2],
            "https://test.lavka-speciy.com/index.php?route=product/product&product_id=" . $id,
            20,
            $id === 1 ? 2 : 0,
            $id === 1 ? 4.5 : null,
            80 - $i * 10,
            \ReviewFactory\now(),
        ],
    );
}
foreach (["deluxe" => 72, "spicery" => 48, "banka" => 19, "specii" => 8] as $source => $count) {
    $host = [
        "deluxe" => "deluxe.com.ua",
        "spicery" => "spiceryshop.com.ua",
        "banka" => "banka-speciy.in.ua",
        "specii" => "www.specii.in.ua",
    ][$source];
    \ReviewFactory\execute(
        $db,
        "INSERT INTO competitors(product_id,source_id,url,confirmed,match_method,review_count,average_rating,samples,last_success,next_check) VALUES(?,?,?,?,?,?,?,?,?,?)",
        [
            1,
            $source,
            "https://" . $host . "/synthetic-example",
            1,
            "manual",
            $count,
            4.7,
            \ReviewFactory\json([
                "Это синтетический пример справочного текста для проверки интерфейса.",
            ]),
            \ReviewFactory\now(),
            \ReviewFactory\now(),
        ],
    );
}
\ReviewFactory\execute($db, "INSERT INTO shop_reviews VALUES(1,1,?,?,?,?,?)", [
    "customer",
    "Демо-покупатель",
    "Добавляю в утренний кофе. Приятный аромат, мелкий помол.",
    5,
    gmdate("Y-m-d H:i:s"),
]);
\ReviewFactory\rebuildQueue($db);
$review = [
    "id" => 17,
    "product_id" => 1,
    "author" => "Покупатель · тест",
    "body" => "Подскажите, как лучше хранить корицу после открытия?",
    "rating" => 3,
    "status" => 1,
    "published_at" => "2026-10-20 12:00:00",
];
\ReviewFactory\execute(
    $db,
    "INSERT INTO reply_reviews(id,product_id,product_name,author,body,rating,status,published_at,shop_reply,context_hash,source_database,search_text,updated_at) VALUES(17,1,?,?,?,?,?,?,?,?,?,?,?)",
    [
        $products[0][0],
        $review["author"],
        $review["body"],
        3,
        1,
        $review["published_at"],
        "",
        \ReviewFactory\replyContext($review),
        "lavkasp_test",
        mb_strtolower($products[0][0] . " " . $review["body"]),
        \ReviewFactory\now(),
    ],
);
echo "Synthetic fixture created\n";
