<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/common.php';

/** Local configuration only. Never opens MySQL or returns credentials to HTTP. */
function sandbox_runtime_configuration(string $directory): array
{
    $real = realpath($directory);
    if (!$real || $real !== rtrim($directory, '/') || is_link($directory)) throw new \RuntimeException('sandbox_directory_invalid');
    foreach (['sandbox.json', 'source.json'] as $name) {
        $path = $real . '/' . $name;
        if (!is_file($path) || is_link($path) || filesize($path) > 65536) throw new \RuntimeException('sandbox_configuration_missing');
    }
    try {
        $marker = decode(file_get_contents($real . '/sandbox.json'));
        $source = decode(file_get_contents($real . '/source.json'));
    } catch (\Throwable $ignored) { throw new \RuntimeException('sandbox_configuration_invalid'); }
    if (!is_array($marker) || !is_array($source)
        || ($marker['environment'] ?? null) !== 'sandbox' || ($marker['database'] ?? null) !== 'lavkasp_test'
        || ($marker['allowed_shop_database'] ?? null) !== 'lavkasp_test' || ($marker['source_user'] ?? null) !== 'lavkasp_sandbox'
        || ($marker['data_dir'] ?? null) !== $real || ($marker['public_url'] ?? null) !== 'https://test.lavka-speciy.com/analytics'
        || ($marker['test_store_verified'] ?? false) !== true) throw new \RuntimeException('sandbox_marker_invalid');
    if (($source['database'] ?? null) !== 'lavkasp_test' || ($source['user'] ?? null) !== 'lavkasp_sandbox'
        || ($source['prefix'] ?? 'oc_') !== 'oc_' || !in_array($source['host'] ?? null, ['127.0.0.1', 'localhost'], true)
        || (int) ($source['port'] ?? 3306) !== 3306 || !empty($source['unix_socket'])) throw new \RuntimeException('sandbox_source_forbidden');
    require_once __DIR__ . '/SandboxEnvironment.php';
    $previous = $marker['previous_data_dir'] ?? '';
    $shop = $marker['shop_root'] ?? '';
    if (!is_string($previous) || !is_string($shop)) throw new \RuntimeException('sandbox_marker_invalid');
    sandbox_validate_layout($previous, $real, $shop);
    sandbox_store_checks($shop, $marker['admin_config'] ?? null);
    return ['directory' => $real, 'marker' => $marker, 'source' => $source];
}

/** A sandbox CLI cannot be redirected to a different source via --config. */
function sandbox_assert_source(string $directory, array $source, string $configFile): void
{
    if (!file_exists($directory . '/sandbox.json') && !is_link($directory . '/sandbox.json')) return;
    $configuration = sandbox_runtime_configuration($directory);
    if (is_link($configFile) || realpath($configFile) !== $configuration['directory'] . '/source.json') throw new \RuntimeException('sandbox_source_forbidden');
    foreach (['database', 'user', 'host', 'password'] as $key) {
        if (($source[$key] ?? null) !== ($configuration['source'][$key] ?? null)) throw new \RuntimeException('sandbox_source_forbidden');
    }
}

function analytics_environment_summary(string $directory): array
{
    $result = ['mode' => 'read_only', 'database' => null, 'label' => 'Кабинет · цены магазина не изменяются',
        'message' => 'Аналитика заказов и наблюдение за конкурентами. Запись цен выключена.'];
    if (!file_exists($directory . '/sandbox.json') && !is_link($directory . '/sandbox.json')) return $result;
    try {
        sandbox_runtime_configuration($directory);
        return ['mode' => 'sandbox', 'database' => 'lavkasp_test', 'label' => 'ПЕСОЧНИЦА · lavkasp_test',
            'message' => 'Продажи и каталог тестового магазина. Изменение цен доступно только после проверки плана.'];
    } catch (\Throwable $ignored) {
        return ['mode' => 'blocked', 'database' => null, 'label' => 'Песочница не прошла проверку',
            'message' => 'Проверьте тестовый источник и конфигурацию магазина. Изменение цен выключено.'];
    }
}
