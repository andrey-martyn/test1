<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Assets.php';

function assets_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function assets_rejects(callable $action, string $message): void
{
    try {
        $action();
    } catch (RuntimeException $error) {
        return;
    }
    throw new RuntimeException($message);
}

function assets_urls(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument();
        assets_expect($document->loadHTML($html, LIBXML_NONET), 'HTML can be parsed');
        $urls = [];
        foreach ($document->getElementsByTagName('script') as $script) {
            if ($script->hasAttribute('src')) $urls[] = $script->getAttribute('src');
        }
        foreach ($document->getElementsByTagName('link') as $link) {
            if ($link->getAttribute('rel') === 'stylesheet') $urls[] = $link->getAttribute('href');
        }
        foreach ($document->getElementsByTagName('img') as $img) {
            if ($img->hasAttribute('src')) $urls[] = $img->getAttribute('src');
        }
        return $urls;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function assets_named_urls(string $html): array
{
    $named = [];
    foreach (assets_urls($html) as $url) {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        assets_expect(isset($query['route']) && is_string($query['route']), 'Every resource has a static route');
        $named[basename($query['route'])] = $url;
    }
    return $named;
}

function assets_remove_tree(string $directory): void
{
    foreach (scandir($directory) as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) assets_remove_tree($path);
        else unlink($path);
    }
    rmdir($directory);
}

foreach (['app.js', 'business-navigation.js', 'help_2.js', 'style.css', 'countries.geojson', 'A-2_B.css', 'brand-chili.png'] as $name) {
    assets_expect(\Lavka\valid_static_asset_name($name), 'Allowed static resource: ' . $name);
}
foreach (['', '.', '..', '../source.json', '/etc/passwd', '.env', 'source.json', 'access-php.json',
    'App.php', 'foo.php', 'foo.json', 'foo.svg', 'folder/app.js', 'app.js/..', 'app.js?x=1',
    'app.JS', '%2e%2e%2fapp.js', "app.js\n", "app.js\0"] as $name) {
    assets_expect(!\Lavka\valid_static_asset_name($name), 'Private paths and unsupported extensions are rejected');
}

$static = dirname(__DIR__) . '/static';
$realTemplates = 0;
foreach (['index.html', 'login.html'] as $name) {
    $file = $static . '/' . $name;
    preg_match_all('~(?:src|href)="__ANALYTICS_BASE__/static/([A-Za-z0-9_-]+\.(?:js|css|png))(?:\?[^"]*)?"~', file_get_contents($file), $original);
    assets_expect(count($original[1]) > 0, 'Real template contains JS/CSS resources');
    foreach (['', '/analytics', '/shop/reports'] as $base) {
        $html = \Lavka\asset_page_html($file, $base, $static);
        assets_expect(strpos($html, '__ANALYTICS_BASE__') === false, 'Base placeholder is completely expanded');
        assets_expect(strpos($html, '&amp;v=') !== false && strpos($html, '&v=') === false, 'Asset query ampersands are escaped in HTML');
        assets_expect(strpos($html, $base . '/static/') === false, 'The page does not request copied public/static resources');
        $urls = assets_urls($html);
        assets_expect(count($urls) === count($original[1]), 'All script and stylesheet references are retained');
        $seen = [];
        foreach ($urls as $url) {
            assets_expect(parse_url($url, PHP_URL_HOST) === null, 'Resources remain on the same origin');
            assets_expect(parse_url($url, PHP_URL_PATH) === $base . '/index.php', 'Resources use the launcher at the correct mount');
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            assets_expect(count($query) === 2 && isset($query['route'], $query['v']), 'Only the route and content fingerprint are present');
            $resource = substr($query['route'], strlen('/static/'));
            assets_expect($query['route'] === '/static/' . $resource && \Lavka\valid_static_asset_name($resource), 'The route names an allowlisted static file');
            $fingerprint = substr(hash_file('sha256', $static . '/' . $resource), 0, 16);
            assets_expect($query['v'] === $fingerprint, 'The fingerprint matches the current source file');
            assets_expect($url === $base . '/index.php?route=' . rawurlencode('/static/' . $resource) . '&v=' . $fingerprint, 'DOM-decoded URL has the exact expected encoding');
            $seen[] = $resource;
        }
        $expected = $original[1];
        sort($expected); sort($seen);
        assets_expect($seen === $expected, 'Every original JS/CSS reference is rewritten once');
        $realTemplates++;
    }
}

$directory = sys_get_temp_dir() . '/lavka-assets-test-' . bin2hex(random_bytes(6));
\Lavka\private_dir($directory);
try {
    $assets = $directory . '/assets';
    mkdir($assets, 0700);
    file_put_contents($assets . '/app.js', "const release = 1;\n");
    file_put_contents($assets . '/style.css', 'body{color:green}');
    $file = $directory . '/template.html';
    file_put_contents($file, '<!doctype html><html><head><link rel="stylesheet" href="__ANALYTICS_BASE__/static/style.css?v=old-css"><script src="__ANALYTICS_BASE__/static/app.js?v=20261023-business"></script></head><body><a href="__ANALYTICS_BASE__/">Cabinet</a></body></html>');
    $first = \Lavka\asset_page_html($file, '/analytics', $assets);
    assets_expect($first === \Lavka\asset_page_html($file, '/analytics', $assets), 'Unchanged contents produce stable asset URLs');
    assets_expect(strpos($first, 'old-css') === false && strpos($first, '20261023-business') === false, 'Manual version parameters are consumed rather than appended');
    assets_expect(strpos($first, 'href="/analytics/"') !== false, 'Ordinary page links still receive the mount path');
    $firstUrls = assets_named_urls($first);
    $mtime = filemtime($assets . '/app.js');
    file_put_contents($assets . '/app.js', "const release = 2;\n");
    touch($assets . '/app.js', $mtime);
    clearstatcache(true, $assets . '/app.js');
    $nextUrls = assets_named_urls(\Lavka\asset_page_html($file, '/analytics', $assets));
    assets_expect($firstUrls['app.js'] !== $nextUrls['app.js'], 'A same-size, same-mtime content change changes the fingerprint');
    assets_expect($firstUrls['style.css'] === $nextUrls['style.css'], 'An unchanged neighbouring resource keeps its URL');

    file_put_contents($file, '<script src="__ANALYTICS_BASE__/static/missing.js"></script>');
    assets_rejects(function () use ($file, $assets) {
        \Lavka\asset_page_html($file, '/analytics', $assets);
    }, 'Missing resources must stop rendering');

    file_put_contents($directory . '/outside.js', '/* synthetic outside file */');
    assets_expect(symlink($directory . '/outside.js', $assets . '/linked.js'), 'The synthetic symlink can be created');
    file_put_contents($file, '<script src="__ANALYTICS_BASE__/static/linked.js"></script>');
    assets_rejects(function () use ($file, $assets) {
        \Lavka\asset_page_html($file, '/analytics', $assets);
    }, 'Symlinked resources must stop rendering');
} finally {
    assets_remove_tree($directory);
}

echo \Lavka\encode([
    'real_templates_and_mounts' => $realTemplates,
    'same_origin_and_html_escaping' => 'passed',
    'content_fingerprints' => 'passed',
    'old_manual_versions' => 'consumed',
    'missing_and_symlinked_assets' => 'refused',
    'static_filename_allowlist' => 'passed',
]) . "\n";
