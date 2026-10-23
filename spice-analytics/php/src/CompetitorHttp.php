<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/common.php';

const COMPETITOR_USER_AGENT = 'LavkaCompetitorMonitor/1.0 (+https://lavka-speciy.com/)';
const COMPETITOR_MAX_BODY = 2097152;

final class CompetitorHttpError extends \RuntimeException
{
    public $errorCode;
    public $httpStatus;
    public function __construct(string $errorCode, ?int $httpStatus = null)
    {
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        parent::__construct($errorCode);
    }
}

/** Fixed public origins. This is deliberately independent of submitted targets. */
function competitors_http_hosts(): array
{
    return ['lavka-speciy.com', 'www.lavka-speciy.com', 'deluxe.com.ua', 'www.deluxe.com.ua',
        'spiceryshop.com.ua', 'www.spiceryshop.com.ua', 'banka-speciy.in.ua', 'www.banka-speciy.in.ua',
        'specii.in.ua', 'www.specii.in.ua'];
}

function competitors_http_url(string $value): array
{
    if (strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
        throw new CompetitorHttpError('url_rejected');
    }
    $url = parse_url($value);
    if (!$url || ($url['scheme'] ?? '') !== 'https' || !isset($url['host']) ||
        !in_array(strtolower($url['host']), competitors_http_hosts(), true) ||
        isset($url['user']) || isset($url['pass']) || isset($url['fragment']) ||
        (isset($url['port']) && $url['port'] !== 443)) {
        throw new CompetitorHttpError('url_rejected');
    }
    $path = $url['path'] ?? '/';
    $decoded = rawurldecode(rawurldecode($path));
    if (preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded) ||
        preg_match('~(?:^|/)(?:admin|administrator|login|logout|signin|auth|account|customer|cart|checkout|basket|order|analytics|api)(?:/|\.|$)~i', $decoded)) {
        throw new CompetitorHttpError('url_rejected');
    }
    $segments = [];
    foreach (explode('/', $path) as $part) {
        $plain = rawurldecode(rawurldecode($part));
        if ($plain === '..' || $plain === '.') throw new CompetitorHttpError('url_rejected');
        $segments[] = $part;
    }
    $query = $url['query'] ?? '';
    if (preg_match('/[\x00-\x1f\x7f]/', rawurldecode($query))) throw new CompetitorHttpError('url_rejected');
    $params = [];
    parse_str($query, $params);
    foreach ($params as $key => $param) {
        if (!is_string($param) || preg_match('/(?:password|passwd|secret|token|session|auth|email|customer|redirect|return|callback|api.?key)/i', (string)$key) ||
            preg_match('/^(?:add(?:[-_]?to[-_]?cart)?|action|url|coupon|code|quantity|remove|cmd|do)$/i', (string)$key)) {
            throw new CompetitorHttpError('url_rejected');
        }
        if (strtolower((string)$key) === 'route' && !in_array($param, ['product/product','product/category','product/latest','product/special','information/information','feed/google_sitemap','extension/feed/google_sitemap'], true)) throw new CompetitorHttpError('url_rejected');
        if (strtolower((string)$key) === 'product_id' && !preg_match('/^[1-9][0-9]{0,9}$/D', $param)) throw new CompetitorHttpError('url_rejected');
    }
    $host = strtolower($url['host']);
    return ['url' => 'https://' . $host . $path . ($query !== '' ? '?' . $query : ''),
        'host' => $host, 'origin' => 'https://' . $host, 'path' => $path, 'query' => $query];
}

function competitors_ip_in(string $ip, string $network, int $bits): bool
{
    $address = @inet_pton($ip);
    $prefix = @inet_pton($network);
    if ($address === false || $prefix === false || strlen($address) !== strlen($prefix)) return false;
    $bytes = intdiv($bits, 8);
    $rest = $bits % 8;
    return substr($address, 0, $bytes) === substr($prefix, 0, $bytes) &&
        (!$rest || (ord($address[$bytes]) & (255 << (8 - $rest))) === (ord($prefix[$bytes]) & (255 << (8 - $rest))));
}

/** Includes multicast, carrier-grade NAT, documentation and transition addresses. */
function competitors_public_ip(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        foreach ([['0.0.0.0',8],['10.0.0.0',8],['100.64.0.0',10],['127.0.0.0',8],['169.254.0.0',16],
            ['172.16.0.0',12],['192.0.0.0',24],['192.0.2.0',24],['192.88.99.0',24],['192.168.0.0',16],
            ['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4],['240.0.0.0',4]] as $range) {
            if (competitors_ip_in($ip, $range[0], $range[1])) return false;
        }
        return true;
    }
    if (!competitors_ip_in($ip, '2000::', 3)) return false;
    foreach ([['2001::',23],['2001:db8::',32],['2002::',16],['3fff::',20]] as $range) {
        if (competitors_ip_in($ip, $range[0], $range[1])) return false;
    }
    return true;
}

function competitors_resolve_public(string $host, ?callable $resolver = null): array
{
    if (!in_array($host, competitors_http_hosts(), true)) throw new CompetitorHttpError('url_rejected');
    $ips = $resolver ? $resolver($host) : null;
    if (!$resolver) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            if (isset($record['ip'])) $ips[] = $record['ip'];
            if (isset($record['ipv6'])) $ips[] = $record['ipv6'];
        }
    }
    if (!is_array($ips) || !$ips || count($ips) > 32) throw new CompetitorHttpError('dns_error');
    foreach ($ips as $ip) {
        if (!is_string($ip) || !competitors_public_ip($ip)) throw new CompetitorHttpError('private_address');
    }
    $ips = array_values(array_unique($ips));
    usort($ips, function ($a, $b) { return (strpos($a, ':') !== false) <=> (strpos($b, ':') !== false); });
    return $ips;
}

/** A proxy's CONNECT resolution cannot be pinned with CURLOPT_RESOLVE. Fail closed. */
function competitors_proxy_configured(): bool
{
    foreach (['HTTPS_PROXY','https_proxy','ALL_PROXY','all_proxy'] as $key) {
        if (trim((string)getenv($key)) !== '') return true;
    }
    return false;
}

function competitors_http_request(array $url, int $milliseconds): array
{
    if (!extension_loaded('curl')) throw new CompetitorHttpError('curl_unavailable');
    if (competitors_proxy_configured()) throw new CompetitorHttpError('proxy_unsupported');
    $resolvedAt = microtime(true);
    $ips = competitors_resolve_public($url['host']);
    // PHP's system resolver has its own timeout. Do not grant the HTTP request
    // a fresh full timeout after the resolver has already spent this budget.
    $milliseconds -= (int)ceil((microtime(true) - $resolvedAt) * 1000);
    if ($milliseconds < 1) throw new CompetitorHttpError('timeout');
    $ip = $ips[0];
    $resolveAddress = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
    $body = '';
    $headers = [];
    $headerBytes = 0;
    $tooLarge = false;
    $handle = curl_init($url['url']);
    if ($handle === false) throw new CompetitorHttpError('network_error');
    try {
        curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $milliseconds), CURLOPT_TIMEOUT_MS => $milliseconds,
            CURLOPT_USERAGENT => COMPETITOR_USER_AGENT, CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/plain;q=0.9,application/xml;q=0.8', 'Accept-Language: uk,ru;q=0.9,en;q=0.5'],
            CURLOPT_RESOLVE => [$url['host'] . ':443:' . $resolveAddress],
            CURLOPT_IPRESOLVE => strpos($ip, ':') !== false ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
            CURLOPT_HEADERFUNCTION => function ($unused, $line) use (&$headers, &$headerBytes, &$tooLarge) {
                $headerBytes += strlen($line);
                if ($headerBytes > 65536) { $tooLarge = true; return 0; }
                if (preg_match('~^HTTP/\S+\s+\d+~i', $line)) $headers = [];
                $colon = strpos($line, ':');
                if ($colon !== false) $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($unused, $chunk) use (&$body, &$tooLarge) {
                if (strlen($body) + strlen($chunk) > COMPETITOR_MAX_BODY) { $tooLarge = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($tooLarge) throw new CompetitorHttpError('response_too_large', $status ?: null);
        if ($ok === false) {
            $number = curl_errno($handle);
            $error = $number === CURLE_OPERATION_TIMEDOUT ? 'timeout' :
                (in_array($number, [CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CONNECT_ERROR], true) ? 'tls_error' : 'network_error');
            throw new CompetitorHttpError($error, $status ?: null);
        }
        return ['body' => $body, 'status' => $status, 'headers' => $headers];
    } finally {
        curl_close($handle);
    }
}

function competitors_redirect_url(string $base, string $location): string
{
    if (preg_match('/[\x00-\x20\x7f\\\\]/', $location)) throw new CompetitorHttpError('redirect_rejected');
    $location = explode('#', $location, 2)[0];
    if ($location === '') return $base;
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location)) return $location;
    $b = competitors_http_url($base);
    if (substr($location, 0, 2) === '//') return 'https:' . $location;
    if ($location[0] === '?') return $b['origin'] . $b['path'] . $location;
    $path = $location[0] === '/' ? $location : substr($b['path'], 0, strrpos($b['path'], '/') + 1) . $location;
    $parts = explode('?', $path, 2);
    $clean = [];
    foreach (explode('/', $parts[0]) as $part) {
        if ($part === '.') continue;
        if ($part === '..') { array_pop($clean); continue; }
        $clean[] = $part;
    }
    return $b['origin'] . implode('/', $clean) . (isset($parts[1]) ? '?' . $parts[1] : '');
}

/** Injected transports exist only for deterministic tests; no environment bypass. */
function competitors_http_fetch(string $url, ?callable $transport = null, ?callable $beforeRequest = null, bool $allowGzip = false): array
{
    $deadline = microtime(true) + 12;
    $sourceHost = preg_replace('/^www\./', '', competitors_http_url($url)['host']);
    for ($redirects = 0; $redirects <= 3; $redirects++) {
        $checked = competitors_http_url($url);
        // Another monitored shop is still a different source. Do not attribute
        // its prices to the target whose URL redirected there.
        if (preg_replace('/^www\./', '', $checked['host']) !== $sourceHost) throw new CompetitorHttpError('redirect_rejected');
        if ($deadline <= microtime(true)) throw new CompetitorHttpError('timeout');
        // The runner applies robots and persistent source pacing to every hop,
        // including redirects between the source's bare and www origins.
        if ($beforeRequest) $beforeRequest($checked['url']);
        $milliseconds = (int)floor(($deadline - microtime(true)) * 1000);
        if ($milliseconds < 1) throw new CompetitorHttpError('timeout');
        $response = $transport ? $transport($checked['url'], ['timeout_ms' => $milliseconds, 'max_bytes' => COMPETITOR_MAX_BODY,
            'user_agent' => COMPETITOR_USER_AGENT]) : competitors_http_request($checked, $milliseconds);
        if (!is_array($response) || !isset($response['status']) || !is_int($response['status']) || !is_string($response['body'] ?? null)) {
            throw new CompetitorHttpError('invalid_response');
        }
        $status = $response['status'];
        $headers = array_change_key_case($response['headers'] ?? [], CASE_LOWER);
        if (strlen($response['body']) > COMPETITOR_MAX_BODY) throw new CompetitorHttpError('response_too_large', $status);
        if ($status >= 300 && $status < 400) {
            if ($redirects >= 3 || !is_string($headers['location'] ?? null) || $headers['location'] === '') throw new CompetitorHttpError('redirect_rejected', $status);
            $url = competitors_redirect_url($checked['url'], $headers['location']);
            continue;
        }
        if ($status === 429) throw new CompetitorHttpError('rate_limited', $status);
        if ($status === 401 || $status === 403) throw new CompetitorHttpError('blocked', $status);
        if ($status === 404 || $status === 410) throw new CompetitorHttpError('not_found', $status);
        if ($status >= 500) throw new CompetitorHttpError('upstream_error', $status);
        if ($status < 200 || $status >= 300) throw new CompetitorHttpError('http_error', $status);
        $type = strtolower(trim(explode(';', (string)($headers['content-type'] ?? $response['content_type'] ?? ''))[0]));
        if ($type !== '' && !in_array($type, array_merge(['text/html', 'application/xhtml+xml', 'text/plain', 'text/xml', 'application/xml', 'application/json'], $allowGzip?['application/gzip','application/x-gzip','application/octet-stream']:[]), true)) {
            throw new CompetitorHttpError('unsupported_content', $status);
        }
        // Review/contact widgets can legitimately include reCAPTCHA on a product.
        // Only clear interstitial challenge evidence blocks the whole response.
        if (preg_match('~(?:cf-chl-|/cdn-cgi/challenge-platform/|<title[^>]*>\s*(?:just a moment|attention required|captcha)|<form[^>]+id=["\x27]challenge-form["\x27])~i', $response['body'])) {
            throw new CompetitorHttpError('captcha', $status);
        }
        return ['body' => $response['body'], 'status' => $status, 'url' => $checked['url'], 'content_type' => $type];
    }
    throw new CompetitorHttpError('redirect_rejected');
}

/** Most specific matching agent, longest rule, allow on an equal-length tie. */
function competitors_robots_parse(string $text, string $url, string $agent = COMPETITOR_USER_AGENT): array
{
    if (strlen($text) > 131072 || strpos($text, "\0") !== false || preg_match('~<\s*(?:html|!doctype)~i', substr($text, 0, 1024))) {
        throw new CompetitorHttpError('robots_invalid');
    }
    $groups = [];
    $current = ['agents' => [], 'rules' => [], 'delay' => 0];
    $directives = false;
    foreach (preg_split('/\r\n|\r|\n/', preg_replace('/^\xEF\xBB\xBF/', '', $text)) as $line) {
        $line = trim(explode('#', $line, 2)[0]);
        if ($line === '' || strpos($line, ':') === false) continue;
        list($field, $value) = array_map('trim', explode(':', $line, 2));
        $field = strtolower($field);
        if ($field === 'user-agent') {
            if ($directives) { $groups[] = $current; $current = ['agents' => [], 'rules' => [], 'delay' => 0]; $directives = false; }
            if ($value !== '') $current['agents'][] = strtolower($value);
        } elseif ($current['agents']) {
            if ($field === 'allow' || $field === 'disallow') {
                $directives = true;
                if ($value !== '') $current['rules'][] = ['allow' => $field === 'allow', 'path' => $value];
            } elseif ($field === 'crawl-delay' && is_numeric($value) && (float)$value >= 0) {
                $directives = true;
                if ((float)$value > 86400) throw new CompetitorHttpError('robots_invalid');
                $current['delay'] = (float)$value;
            }
        }
    }
    if ($current['agents']) $groups[] = $current;
    $matching = [];
    $specificity = -1;
    foreach ($groups as $group) {
        $score = -1;
        foreach ($group['agents'] as $token) {
            if ($token === '*') $score = max($score, 0);
            elseif (strpos(strtolower($agent), $token) !== false) $score = max($score, strlen($token));
        }
        if ($score > $specificity) { $matching = [$group]; $specificity = $score; }
        elseif ($score === $specificity && $score >= 0) $matching[] = $group;
    }
    $parsed = competitors_http_url($url);
    $path = rawurldecode($parsed['path'] . ($parsed['query'] !== '' ? '?' . $parsed['query'] : ''));
    $allowed = true;
    $best = -1;
    $delay = 0.0;
    foreach ($matching as $group) {
        $delay = max($delay, $group['delay']);
        foreach ($group['rules'] as $rule) {
            $pattern = rawurldecode($rule['path']);
            $anchor = substr($pattern, -1) === '$';
            if ($anchor) $pattern = substr($pattern, 0, -1);
            $regex = '~^' . str_replace('\\*', '.*', preg_quote($pattern, '~')) . ($anchor ? '$' : '') . '~';
            if (!preg_match($regex, $path)) continue;
            $length = strlen(str_replace('*', '', $pattern));
            if ($length > $best || ($length === $best && $rule['allow'])) { $best = $length; $allowed = $rule['allow']; }
        }
    }
    return ['allowed' => $allowed, 'crawl_delay' => $delay];
}

/** Private bounded robots cache; failure is cached for an hour and fails closed. */
function competitors_robots_policy(string $url, string $directory, ?callable $transport = null, ?int $now = null, ?callable $beforeRequest = null): array
{
    $checked = competitors_http_url($url);
    $now = $now ?? time();
    $cacheDirectory = $directory . '/competitor-http-cache';
    private_dir($cacheDirectory);
    $path = $cacheDirectory . '/robots-' . hash('sha256', $checked['origin']) . '.json';
    $cache = null;
    if (is_file($path) && filesize($path) <= 200000) {
        try { $cache = decode(file_get_contents($path)); } catch (\Throwable $e) { $cache = null; }
    }
    $fetched = false;
    if (!is_array($cache) || ($cache['origin'] ?? '') !== $checked['origin'] || (int)($cache['expires_at'] ?? 0) <= $now) {
        $fetched = true;
        try {
            $robotsGuard = function (string $requestUrl) use ($checked, $beforeRequest): void {
                $request = competitors_http_url($requestUrl);
                if (preg_replace('/^www\./', '', $request['host']) !== preg_replace('/^www\./', '', $checked['host'])) {
                    throw new CompetitorHttpError('robots_invalid');
                }
                if ($beforeRequest) $beforeRequest($requestUrl);
            };
            $response = competitors_http_fetch($checked['origin'] . '/robots.txt', $transport, $robotsGuard);
            competitors_robots_parse($response['body'], $url);
            $cache = ['origin' => $checked['origin'], 'expires_at' => $now + 86400, 'body' => $response['body']];
        } catch (CompetitorHttpError $error) {
            if (in_array($error->httpStatus, [404, 410], true)) {
                $cache = ['origin' => $checked['origin'], 'expires_at' => $now + 86400, 'body' => ''];
            } else {
                $cache = ['origin' => $checked['origin'], 'expires_at' => $now + 3600, 'error_code' => $error->errorCode, 'http_status' => $error->httpStatus];
            }
        }
        atomic_write($path, encode($cache));
    }
    if (isset($cache['error_code'])) {
        $code = in_array($cache['error_code'], ['rate_limited','blocked','proxy_unsupported','robots_invalid'], true) ? $cache['error_code'] : 'robots_unavailable';
        throw new CompetitorHttpError($code, isset($cache['http_status']) ? (int)$cache['http_status'] : null);
    }
    $policy = competitors_robots_parse((string)($cache['body'] ?? ''), $url);
    $policy['fetched_network'] = $fetched;
    preg_match_all('~^Sitemap:\s*(https://\S+)~im',(string)($cache['body']??''),$maps);$policy['sitemaps']=array_slice($maps[1],0,20);
    return $policy;
}
