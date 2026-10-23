<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/common.php';

function valid_static_asset_name(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]+\.(?:js|css|geojson|png)$/D', $name);
}

function asset_page_html(string $file, string $base, string $assets): string
{
    $html = file_get_contents($file);
    // HTML comes from this checkout. Its JS/CSS must also come from this
    // checkout, even if the web server still has an older public/static copy.
    $html = preg_replace_callback('~__ANALYTICS_BASE__/static/([A-Za-z0-9_-]+\.(?:js|css|png))(?:\?v=[A-Za-z0-9_-]+)?~', function ($match) use ($base, $assets) {
        $path = $assets . '/' . $match[1];
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new \RuntimeException('Не найден ресурс интерфейса');
        }
        $digest = hash_file('sha256', $path);
        if ($digest === false) {
            throw new \RuntimeException('Не удалось проверить ресурс интерфейса');
        }
        $url = $base . '/index.php?route=' . rawurlencode('/static/' . $match[1]) . '&v=' . substr($digest, 0, 16);
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }, $html);
    return str_replace('__ANALYTICS_BASE__', htmlspecialchars($base, ENT_QUOTES, 'UTF-8'), $html);
}
