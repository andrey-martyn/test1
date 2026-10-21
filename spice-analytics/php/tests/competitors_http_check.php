<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/CompetitorHttp.php';

function expect_http(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function rejects_http(string $code, callable $function): void
{
    try { $function(); }
    catch (\Lavka\CompetitorHttpError $error) {
        expect_http($error->errorCode === $code, 'Expected ' . $code . ', got ' . $error->errorCode);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
}

$directory = sys_get_temp_dir() . '/lavka-competitor-http-' . bin2hex(random_bytes(6));
\Lavka\private_dir($directory);
try {
    foreach (['127.0.0.1','10.0.0.1','172.16.0.1','192.168.1.1','169.254.169.254','100.64.1.2',
        '224.0.0.1','240.0.0.1','192.0.2.1','198.18.0.1','198.51.100.2','203.0.113.1',
        '::1','::','fc00::1','fe80::1','ff02::1','::ffff:8.8.8.8','64:ff9b::808:808',
        '2001:db8::1','2002:0808:0808::1','2001::1','2001:2::1','2001:10::1','2001:20::1','3fff::1'] as $private) {
        expect_http(!\Lavka\competitors_public_ip($private), 'Rejected non-public IP ' . $private);
    }
    foreach (['8.8.8.8','1.1.1.1','78.47.15.216','2606:4700:4700::1111'] as $public) {
        expect_http(\Lavka\competitors_public_ip($public), 'Accepted public IP ' . $public);
    }
    foreach (['http://deluxe.com.ua/a','https://evil.invalid/a','https://deluxe.com.ua.evil.invalid/',
        'https://deluxe.com.ua:444/a','https://user:secret@deluxe.com.ua/', 'https://127.0.0.1/',
        'https://deluxe.com.ua/cart/add','https://deluxe.com.ua/%61ccount/login',
        'https://deluxe.com.ua/foo/%2e%2e/cart','https://deluxe.com.ua/a?route=checkout/checkout',
        'https://deluxe.com.ua/a?route=product/product&product_id[]=4',
        "https://deluxe.com.ua/a\nHeader:x", 'https://deluxe.com.ua/a#fragment',
        'https://deluxe.com.ua/a?redirect=https://evil.invalid/'] as $bad) {
        rejects_http('url_rejected', function () use ($bad) { \Lavka\competitors_http_url($bad); });
    }
    $legacy = 'https://lavka-speciy.com/index.php?route=product/product&product_id=990';
    expect_http(\Lavka\competitors_http_url($legacy)['url'] === $legacy, 'Own public OpenCart product URL');
    expect_http(\Lavka\competitors_http_url('https://www.specii.in.ua/ua/catalog/vanil/')['host'] === 'www.specii.in.ua', 'www host allowed');
    $ips = \Lavka\competitors_resolve_public('deluxe.com.ua', function () { return ['2606:4700:4700::1111','8.8.8.8']; });
    expect_http($ips[0] === '8.8.8.8', 'Prefer IPv4 but support valid public IPv6');
    rejects_http('private_address', function () {
        \Lavka\competitors_resolve_public('deluxe.com.ua', function () { return ['8.8.8.8','127.0.0.1']; });
    });
    rejects_http('dns_error', function () { \Lavka\competitors_resolve_public('deluxe.com.ua', function () { return []; }); });

    $calls = [];
    $transport = function ($url, $options) use (&$calls) {
        $calls[] = $url;
        expect_http($options['timeout_ms'] <= 12000 && $options['timeout_ms'] > 0, 'Bounded request timeout');
        expect_http(strpos($options['user_agent'], 'LavkaCompetitorMonitor') === 0, 'Identifiable user agent');
        if (count($calls) === 1) return ['status' => 302, 'body' => '', 'headers' => ['Location' => '/ua/product/']];
        return ['status' => 200, 'body' => '<h1>Synthetic spice</h1>', 'headers' => ['Content-Type' => 'text/html; charset=utf-8']];
    };
    $response = \Lavka\competitors_http_fetch('https://deluxe.com.ua/product/', $transport);
    expect_http($response['url'] === 'https://deluxe.com.ua/ua/product/' && count($calls) === 2, 'Validated same-site redirect');
    $calls = 0;
    rejects_http('url_rejected', function () use (&$calls) {
        \Lavka\competitors_http_fetch('https://deluxe.com.ua/product/', function () use (&$calls) {
            $calls++;
            return ['status' => 302, 'body' => '', 'headers' => ['location' => 'http://169.254.169.254/latest/meta-data/']];
        });
    });
    expect_http($calls === 1, 'Unsafe redirect is never requested');
    $calls = 0;
    rejects_http('redirect_rejected', function () use (&$calls) {
        \Lavka\competitors_http_fetch('https://deluxe.com.ua/product/', function () use (&$calls) {
            $calls++;
            return ['status' => 302, 'body' => '', 'headers' => ['location' => 'https://spiceryshop.com.ua/product/']];
        });
    });
    expect_http($calls === 1, 'Another allowed shop cannot replace the attributed source');
    $guarded = [];
    $guardCalls = [];
    rejects_http('robots_disallowed', function () use (&$guarded, &$guardCalls) {
        \Lavka\competitors_http_fetch('https://deluxe.com.ua/public', function ($url) use (&$guarded) {
            $guarded[] = $url;
            return ['status' => 302, 'body' => '', 'headers' => ['location' => '/private']];
        }, function ($url) use (&$guardCalls) {
            $guardCalls[] = $url;
            if (parse_url($url, PHP_URL_PATH) === '/private') throw new \Lavka\CompetitorHttpError('robots_disallowed');
        });
    });
    expect_http(count($guarded) === 1 && count($guardCalls) === 2, 'Every redirected path is guarded before fetching');
    rejects_http('redirect_rejected', function () {
        \Lavka\competitors_http_fetch('https://deluxe.com.ua/a', function () { return ['status' => 302, 'body' => '', 'headers' => ['location' => '/a']]; });
    });
    foreach ([429 => 'rate_limited',403 => 'blocked',404 => 'not_found',503 => 'upstream_error'] as $status => $code) {
        rejects_http($code, function () use ($status) {
            \Lavka\competitors_http_fetch('https://deluxe.com.ua/a', function () use ($status) { return ['status' => $status, 'body' => 'No price']; });
        });
    }
    rejects_http('response_too_large', function () {
        \Lavka\competitors_http_fetch('https://deluxe.com.ua/a', function () { return ['status' => 200, 'body' => str_repeat('x', \Lavka\COMPETITOR_MAX_BODY + 1)]; });
    });
    rejects_http('unsupported_content', function () {
        \Lavka\competitors_http_fetch('https://deluxe.com.ua/a', function () { return ['status' => 200, 'body' => 'fake png', 'headers' => ['content-type' => 'image/png']]; });
    });
    rejects_http('captcha', function () {
        \Lavka\competitors_http_fetch('https://deluxe.com.ua/a', function () { return ['status' => 200, 'body' => '<html><title>Just a moment...</title></html>']; });
    });
    $withReview = \Lavka\competitors_http_fetch('https://deluxe.com.ua/a', function () {
        return ['status' => 200, 'body' => '<html><title>Paprika</title><h1>Paprika</h1><form><div class="g-recaptcha"></div></form></html>'];
    });
    expect_http($withReview['status'] === 200, 'Normal review CAPTCHA does not block product parsing');

    $robots = "User-agent: *\nDisallow: /\n\nUser-agent: LavkaCompetitorMonitor\nDisallow: /private\nAllow: /private/open$\nCrawl-delay: 4\n";
    expect_http(\Lavka\competitors_robots_parse($robots, 'https://deluxe.com.ua/a')['allowed'], 'Specific group takes precedence over wildcard');
    expect_http(!\Lavka\competitors_robots_parse($robots, 'https://deluxe.com.ua/private/other')['allowed'], 'Disallow respected');
    $policy = \Lavka\competitors_robots_parse($robots, 'https://deluxe.com.ua/private/open');
    expect_http($policy['allowed'] && $policy['crawl_delay'] === 4.0, 'Longest allow and crawl delay');
    expect_http(!\Lavka\competitors_robots_parse($robots, 'https://deluxe.com.ua/private/open/suffix')['allowed'], 'End-of-path rule is anchored');
    $robots = "User-agent: *\nDisallow: /*?page=*\nAllow: /public\nDisallow: /public\n";
    expect_http(!\Lavka\competitors_robots_parse($robots, 'https://deluxe.com.ua/a?page=1')['allowed'], 'Wildcard query rule');
    expect_http(\Lavka\competitors_robots_parse($robots, 'https://deluxe.com.ua/public')['allowed'], 'Allow wins equal specificity');
    rejects_http('robots_invalid', function () { \Lavka\competitors_robots_parse('<!doctype html><title>error</title>', 'https://deluxe.com.ua/'); });
    $calls = 0;
    $transport = function () use (&$calls) { $calls++; return ['status' => 200, 'body' => "User-agent: *\nDisallow: /private\n", 'headers' => ['content-type' => 'text/plain']]; };
    $a = \Lavka\competitors_robots_policy('https://deluxe.com.ua/a', $directory, $transport, 1000000);
    $b = \Lavka\competitors_robots_policy('https://deluxe.com.ua/private', $directory, $transport, 1000010);
    expect_http($calls === 1 && $a['fetched_network'] && !$b['fetched_network'] && !$b['allowed'], 'Private robots cache honors path-specific rules');
    \Lavka\competitors_robots_policy('https://deluxe.com.ua/a', $directory, $transport, 1100000);
    expect_http($calls === 2, 'Robots cache expires');
    $calls = 0;
    $missing = function () use (&$calls) { $calls++; return ['status' => 404, 'body' => 'missing']; };
    expect_http(\Lavka\competitors_robots_policy('https://spiceryshop.com.ua/a', $directory, $missing, 1000000)['allowed'], '404 robots means no published restrictions');
    $down = function () use (&$calls) { $calls++; return ['status' => 503, 'body' => 'down']; };
    rejects_http('robots_unavailable', function () use ($directory, $down) { \Lavka\competitors_robots_policy('https://specii.in.ua/a', $directory, $down, 1000000); });
    $before = $calls;
    rejects_http('robots_unavailable', function () use ($directory, $down) { \Lavka\competitors_robots_policy('https://specii.in.ua/b', $directory, $down, 1000010); });
    expect_http($calls === $before, 'Unavailable robots is cached and never silently ignored');
    $calls = 0;
    rejects_http('robots_unavailable', function () use ($directory, &$calls) {
        \Lavka\competitors_robots_policy('https://banka-speciy.in.ua/a', $directory, function () use (&$calls) {
            $calls++;
            return ['status' => 302, 'body' => '', 'headers' => ['location' => 'https://deluxe.com.ua/robots.txt']];
        }, 1000000);
    });
    expect_http($calls === 1, 'Another source cannot supply robots restrictions');
    $permissions = fileperms($directory . '/competitor-http-cache') & 0777;
    expect_http($permissions === 0700, 'Robots cache remains private');
    echo \Lavka\encode(['http_safety' => 'passed', 'robots_policy_and_cache' => 'passed', 'network_requests' => 0, 'php' => PHP_VERSION]) . "\n";
} finally {
    foreach (glob($directory . '/competitor-http-cache/*') ?: [] as $file) unlink($file);
    if (is_dir($directory . '/competitor-http-cache')) rmdir($directory . '/competitor-http-cache');
    rmdir($directory);
}
