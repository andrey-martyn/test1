<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/Sync.php';

/** Only fixed labels and validated numeric codes may leave this diagnostic. */
function source_failure(\Throwable $error, string $stage, array $warnings = []): array
{
    $state = null;
    $code = null;
    if ($error instanceof \PDOException) {
        $candidate = $error->errorInfo[0] ?? $error->getCode();
        if (is_string($candidate) && preg_match('/^[A-Z0-9]{5}$/D', $candidate)) {
            $state = $candidate;
        }
        $candidate = $error->errorInfo[1] ?? null;
        if ((is_int($candidate) || is_string($candidate)) && preg_match('/^[0-9]{1,5}$/D', (string) $candidate)) {
            $code = (int) $candidate;
        }
    }
    // Driver text can contain credentials or server-supplied content. Inspect it
    // locally, but never print it or include it in JSON, logs, or the web UI.
    $message = strtolower($error->getMessage() . ' ' . implode(' ', $warnings));
    $reason = $stage === 'config' ? 'source_config' : 'unclassified';
    if (preg_match('/certificate verify|certificate verification|peer certificate|did not match|does not match|hostname mismatch|self.signed|issuer certificate/', $message)) {
        $reason = 'tls_certificate';
    } elseif (preg_match('/does not support ssl|doesn.t support ssl|ssl is not supported|server does not support tls|не установил tls/', $message) || $code === 2026 && strpos($message, 'support') !== false) {
        $reason = 'tls_unavailable';
    } elseif ($code === 2026 || strpos($message, 'ssl operation failed') !== false || strpos($message, 'ssl connection error') !== false || strpos($message, 'using ssl') !== false) {
        $reason = 'tls_connection';
    } elseif ($code === 1045) {
        $reason = 'authentication';
    } elseif ($code === 1044 || $code === 1142 || $code === 1143 || $code === 1227) {
        $reason = 'permissions';
    } elseif ($code === 1130) {
        $reason = 'client_host_denied';
    } elseif ($code === 1049) {
        $reason = 'database_missing';
    } elseif ($code === 1146) {
        $reason = 'table_missing';
    } elseif ($code === 1054) {
        $reason = 'column_missing';
    } elseif ($code === 2054 || $code === 2061 || $code === 1251) {
        $reason = 'authentication_driver';
    } elseif (strpos($message, 'getaddrinfo') !== false || strpos($message, 'php_network_getaddresses') !== false || strpos($message, 'name or service not known') !== false) {
        $reason = 'dns';
    } elseif (strpos($message, 'timed out') !== false || strpos($message, 'timeout') !== false) {
        $reason = 'network_timeout';
    } elseif ($code === 2002 || $code === 2003 || $code === 2006 || $code === 2013) {
        $reason = 'network_connection';
    } elseif ($error instanceof SyncError && strpos($message, 'ca-файл') !== false) {
        $reason = 'ca_file';
    } elseif ($error instanceof SyncError && in_array($error->getMessage(), ['ca_file', 'tls_unavailable', 'mysql_greeting', 'network_connection', 'network_timeout', 'source_config'], true)) {
        $reason = $error->getMessage();
    }
    return ['reason' => $reason, 'sqlstate' => $state, 'mysql_code' => $code];
}

function diagnostic_read($stream, int $length): string
{
    $result = '';
    while (strlen($result) < $length) {
        $part = fread($stream, $length - strlen($result));
        if ($part === false || $part === '') {
            $meta = stream_get_meta_data($stream);
            throw new SyncError(!empty($meta['timed_out']) ? 'network_timeout' : 'mysql_greeting');
        }
        $result .= $part;
    }
    return $result;
}

/** Probe remote MySQL TLS without sending any authentication or SQL packets. */
function diagnostic_tls(array $config): void
{
    $host = $config['host'] ?? '';
    if (!empty($config['unix_socket']) || in_array($host, ['localhost', '127.0.0.1', '::1'], true) && empty($config['ssl_ca'])) {
        return;
    }
    // PDO uses a Unix socket for localhost. Leave that case to source_connect.
    if ($host === 'localhost') {
        return;
    }
    $port = (int) ($config['port'] ?? 3306);
    if ($port < 1 || $port > 65535) {
        throw new SyncError('source_config');
    }
    $ca = $config['ssl_ca'] ?? '';
    if (!$ca) {
        $locations = openssl_get_cert_locations();
        $ca = $locations['ini_cafile'] ?: $locations['default_cert_file'];
    }
    if (!is_file($ca)) {
        throw new SyncError('ca_file');
    }
    $context = stream_context_create(['ssl' => [
        'verify_peer' => true, 'verify_peer_name' => true,
        'peer_name' => $host, 'SNI_enabled' => true, 'cafile' => $ca,
    ]]);
    $address = strpos($host, ':') !== false && $host[0] !== '[' ? '[' . $host . ']' : $host;
    $stream = stream_socket_client('tcp://' . $address . ':' . $port, $errno, $error, 8, STREAM_CLIENT_CONNECT, $context);
    if (!$stream) {
        throw new SyncError('network_connection');
    }
    try {
        stream_set_timeout($stream, 8);
        $header = diagnostic_read($stream, 4);
        $length = ord($header[0]) | ord($header[1]) << 8 | ord($header[2]) << 16;
        if ($length < 1 || $length > 16384 || ord($header[3]) !== 0) {
            throw new SyncError('mysql_greeting');
        }
        $greeting = diagnostic_read($stream, $length);
        $end = strpos($greeting, "\0", 1);
        $offset = $end === false ? 0 : $end + 1 + 4 + 8 + 1;
        if (ord($greeting[0]) !== 10 || !$offset || strlen($greeting) < $offset + 2) {
            throw new SyncError('mysql_greeting');
        }
        $capabilities = ord($greeting[$offset]) | ord($greeting[$offset + 1]) << 8;
        if (!($capabilities & 0x0800)) {
            throw new SyncError('tls_unavailable');
        }
        // Protocol 4.1 SSLRequest, sequence 1. It contains no user, password,
        // database, or query. Close immediately after the verified TLS handshake.
        $payload = pack('VVCa23', 0x00088a01, 16777216, 45, str_repeat("\0", 23));
        $packet = "\x20\x00\x00\x01" . $payload;
        if (fwrite($stream, $packet) !== strlen($packet)) {
            throw new SyncError('network_connection');
        }
        if (stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            throw new SyncError('SSL operation failed');
        }
    } finally {
        fclose($stream);
    }
}

/** Read configuration, connect with normal TLS checks, and read schema only. */
function diagnose_source(string $configFile): array
{
    $result = ['state' => 'error', 'php' => PHP_VERSION, 'stage' => 'runtime', 'tables_checked' => 0];
    if (PHP_VERSION_ID < 80200) {
        return $result + ['reason' => 'requires_php82_cli'];
    }
    if (!extension_loaded('pdo_mysql') || !extension_loaded('openssl')) {
        return $result + ['reason' => 'missing_extensions'];
    }
    $warnings = [];
    set_error_handler(function ($severity, $message) use (&$warnings) {
        if (count($warnings) < 8) {
            $warnings[] = substr($message, 0, 2048);
        }
        return true;
    });
    try {
        $result['stage'] = 'config';
        $config = source_config($configFile);
        $result['stage'] = 'transport';
        diagnostic_tls($config);
        $result['stage'] = 'connect';
        $db = source_connect($config);
        $result['stage'] = 'tables';
        foreach (SOURCE_FIELDS as $table => $fields) {
            $result['table'] = $table;
            $physical = ($config['prefix'] ?? 'oc_') . substr($table, 3);
            $columns = implode(',', array_map(function ($field) {
                return '`' . $field . '`';
            }, $fields));
            $stmt = $db->query('SELECT ' . $columns . ' FROM `' . $physical . '` LIMIT 0');
            $stmt->closeCursor();
            ++$result['tables_checked'];
        }
        $result['state'] = 'ok';
        $result['stage'] = 'complete';
        unset($result['table']);
        return $result;
    } catch (\Throwable $error) {
        return $result + source_failure($error, $result['stage'], $warnings);
    } finally {
        restore_error_handler();
    }
}
