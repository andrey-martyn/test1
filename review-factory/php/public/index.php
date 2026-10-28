<?php
// Development entry only. Production deployment generates a fixed-path launcher.
declare(strict_types=1);
require_once dirname(__DIR__) . "/src/App.php";
\ReviewFactory\run(
    getenv("REVIEW_FACTORY_DATA") ?: dirname(__DIR__) . "/data",
    getenv("REVIEW_FACTORY_URL") ?: "http://127.0.0.1:8093/reviews",
    getenv("REVIEW_FACTORY_DEV") === "1",
);
