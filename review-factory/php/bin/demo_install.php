<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/src/Demo.php";

function rf_demoInstall(PDO $db, string $directory, array $config): array
{
    if (
        ($config["environment"] ?? "") !== "test" ||
        rtrim($config["shop_url"] ?? "", "/") !== "https://test.lavka-speciy.com"
    ) {
        throw new \ReviewFactory\Problem("demo_test_only");
    }
    $root = rf_canonical($config["shop_root"]);
    if (basename($root) !== "test") {
        throw new \ReviewFactory\Problem("demo_test_root_required");
    }
    \ReviewFactory\shopConfig($root, "test"); // Literal validation only; no connection or shop writes.
    \ReviewFactory\simulationDirectory($db);
    $source = dirname(__DIR__, 2) . "/demo-ocstore/upload";
    $files = [];
    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        )
        as $file
    ) {
        if ($file->isLink()) {
            throw new \ReviewFactory\Problem("demo_source_symlink");
        }
        if ($file->isFile()) {
            $files[substr($file->getPathname(), strlen($source) + 1)] = file_get_contents(
                $file->getPathname(),
            );
        }
    }
    $runtime = realpath(dirname(__DIR__) . "/src/Demo.php");
    $files["system/library/review_factory_demo_config.php"] =
        "<?php\n// ReviewFactory TEST demo\nreturn " .
        var_export(["data" => realpath($directory), "runtime" => $runtime], true) .
        ";\n";
    // Preflight all destinations before changing any files; reject links and unrelated collisions.
    foreach ($files as $relative => $content) {
        $target = $root . "/" . $relative;
        $parent = dirname($target);
        while ($parent !== $root) {
            if (is_link($parent)) {
                throw new \ReviewFactory\Problem("demo_target_symlink");
            }
            $parent = dirname($parent);
        }
        if (
            is_link($target) ||
            (file_exists($target) &&
                (!is_file($target) ||
                    strpos(file_get_contents($target), "ReviewFactory TEST demo") === false))
        ) {
            throw new \ReviewFactory\Problem("demo_file_collision");
        }
    }
    \ReviewFactory\demoInitialize($db);
    foreach ($files as $relative => $content) {
        $target = $root . "/" . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
            throw new \ReviewFactory\Problem("demo_copy_failed");
        }
        $tmp = tempnam(dirname($target), ".rf-demo-");
        if ($tmp === false) {
            throw new \ReviewFactory\Problem("demo_copy_failed");
        }
        try {
            if (
                file_put_contents($tmp, $content, LOCK_EX) === false ||
                !chmod($tmp, 0644) ||
                !rename($tmp, $target)
            ) {
                throw new \ReviewFactory\Problem("demo_copy_failed");
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
    return [
        "state" => "test_demo_files_ready",
        "environment" => "test",
        "files" => count($files),
        "native_review_writes" => false,
        "next" =>
            "В админке TEST: Расширения → Модули → Комбайн · Демо-отзывы TEST → Установить → Редактировать.",
    ];
}
