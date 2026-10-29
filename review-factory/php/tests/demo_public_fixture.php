<?php
declare(strict_types=1);
require_once __DIR__ . "/demo_fixture.php";
$base = sys_get_temp_dir() . "/rf-demo-browser-" . bin2hex(random_bytes(6));
try {
    $f = demoFixture($base);
    \ReviewFactory\demoModerate(
        $f["private"],
        $f["entry"]["id"],
        "approved",
        "ocstore:7",
        (int) $f["entry"]["revision"],
    );
    echo \ReviewFactory\json(\ReviewFactory\demoRead($f["private"], [1, 2], 1));
} finally {
    demoRemove($base);
}
