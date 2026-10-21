<?php

declare (strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/src/common.php';
function absolute_path(string $path): string
{
    return \Lavka\canonical_path($path);
}
function inside(string $path, string $parent): bool
{
    return $path === $parent || \Lavka\str_starts_with($path, rtrim($parent, '/') . '/');
}
function public_dir(string $path): void
{
    if (!is_dir($path)) {
        // A restrictive SSH umask protects private files, but web server
        // workers still need to traverse these deliberately public directories.
        $previous = umask(0);
        try {
            if (!mkdir($path, 0755, true) && !is_dir($path)) throw new RuntimeException('Не удалось создать публичную папку');
        } finally {
            umask($previous);
        }
    }
    if (!chmod($path, 0755)) throw new RuntimeException('Не удалось назначить права публичной папки');
}
function copy_tree(string $from, string $to): void
{
    public_dir($to);
    foreach (new DirectoryIterator($from) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isLink()) {
            throw new RuntimeException('Символические ссылки в static запрещены');
        }
        $target = $to . '/' . $entry->getFilename();
        if ($entry->isDir()) {
            copy_tree($entry->getPathname(), $target);
        } else {
            if (!copy($entry->getPathname(), $target)) {
                throw new RuntimeException('Не удалось скопировать static');
            }
            chmod($target, 0644);
        }
    }
}
$args = getopt('', ['webroot:', 'data:', 'url:', 'shop-root:']);
try {
    foreach (['webroot', 'data', 'url'] as $key) {
        if (empty($args[$key])) {
            throw new InvalidArgumentException('Укажите --webroot, --data и --url');
        }
    }
    $root = realpath(dirname(__DIR__));
    $webroot = absolute_path($args['webroot']);
    $data = absolute_path($args['data']);
    $address = \Lavka\public_address($args['url']);
    $url = $address['url'];
    if (is_link($webroot) || is_link($data)) {
        throw new InvalidArgumentException('Используйте реальные каталоги, не символические ссылки');
    }
    if (is_dir($webroot)) {
        $webroot = realpath($webroot);
    }
    if (is_dir($data)) {
        $data = realpath($data);
    }
    if (inside($root, $webroot) || inside($webroot, $root) || inside($data, $webroot) || inside($webroot, $data)) {
        throw new InvalidArgumentException('Код, данные и публичная папка должны быть отдельными каталогами');
    }
    $shopRoot = null;
    if ($address['base'] !== '') {
        if (empty($args['shop-root']) || !is_dir($args['shop-root'])) {
            throw new InvalidArgumentException('Для установки в папку укажите --shop-root с настоящим корнем магазина');
        }
        $shopRoot = realpath($args['shop-root']);
        if ($webroot !== $shopRoot . $address['base']) {
            throw new InvalidArgumentException('--webroot должен соответствовать пути URL внутри --shop-root');
        }
        if (inside($root, $shopRoot) || inside($data, $shopRoot)) {
            throw new InvalidArgumentException('Код и данные должны находиться вне всей публичной папки магазина');
        }
    }
    if (is_dir($webroot) && array_diff(scandir($webroot), ['.', '..'])) {
        throw new InvalidArgumentException('Публичная папка не пуста. Файлы магазина не изменены.');
    }
    \Lavka\private_dir($data);
    public_dir($webroot);
    $launcher = "<?php\ndeclare(strict_types=1);\nif (PHP_VERSION_ID < 70300) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); exit('Для кабинета нужен PHP 7.3+ и проверка расширений. Версию PHP магазина менять не требуется.'); }\nputenv('ANALYTICS_DATA_DIR='." . var_export($data, true) . ");\nputenv('ANALYTICS_PUBLIC_URL='." . var_export($url, true) . ");\nrequire " . var_export($root . '/public/index.php', true) . ";\n";
    file_put_contents($webroot . '/index.php', $launcher);
    chmod($webroot . '/index.php', 0644);
    $rules = "Options -Indexes\nDirectoryIndex index.php\n<IfModule mod_headers.c>\nHeader always set X-Robots-Tag \"noindex, nofollow, noarchive\"\nHeader always set Cache-Control \"no-store\"\n</IfModule>\n";
    if ($address['base'] === '') {
        $rules .= "RewriteEngine On\nRewriteCond %{HTTPS} !=on\nRewriteRule ^ " . $address['origin'] . "%{REQUEST_URI} [R=302,L,NE]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^ index.php [L,QSA]\n";
    }
    file_put_contents($webroot . '/.htaccess', $rules);
    chmod($webroot . '/.htaccess', 0644);
    copy_tree($root . '/static', $webroot . '/static');
    \Lavka\atomic_write($data . '/deployment-php.json', \Lavka\encode(['application_root' => $root, 'document_root' => $webroot, 'shop_root' => $shopRoot, 'data_dir' => $data, 'public_url' => $url]));
    echo \Lavka\encode(['prepared' => true, 'runtime' => 'PHP', 'document_root' => $webroot, 'public_url' => $url]) . "\n";
    echo "Файлы подготовлены. Далее задайте пароль, настройте SELECT-пользователя MySQL и проверьте настоящий HTTPS-адрес.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
