<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/SourceDiagnostics.php';
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$secret = 'DO_NOT_DISCLOSE_password_token_customer_email';
$cases = [
    [1045, 'Access denied ' . $secret, 'authentication'],
    [1142, 'SELECT denied ' . $secret, 'permissions'],
    [1146, 'Missing table ' . $secret, 'table_missing'],
    [1054, 'Missing column ' . $secret, 'column_missing'],
    [2002, 'SSL operation failed; certificate verify failed ' . $secret, 'tls_certificate'],
    [2026, "Server doesn't support SSL " . $secret, 'tls_unavailable'],
    [2026, 'SSL connection error ' . $secret, 'tls_connection'],
    [2002, 'Cannot connect to MySQL using SSL ' . $secret, 'tls_connection'],
    [2002, 'php_network_getaddresses getaddrinfo failed ' . $secret, 'dns'],
    [2002, 'Connection refused ' . $secret, 'network_connection'],
    [2002, 'Connection timed out ' . $secret, 'network_timeout'],
    [2054, 'Unknown authentication ' . $secret, 'authentication_driver'],
];
foreach ($cases as $case) {
    [$code, $message, $reason] = $case;
    $error = new PDOException($message);
    $error->errorInfo = ['HY000', $code, $message];
    $result = \Lavka\source_failure($error, 'connect');
    check($result['reason'] === $reason, 'Failure category ' . $reason);
    check($result['mysql_code'] === $code && $result['sqlstate'] === 'HY000', 'Numeric diagnostic');
    check(strpos(json_encode($result), $secret) === false, 'Driver message never leaves diagnostic');
}
$error = new PDOException($secret);
$error->errorInfo = [$secret, $secret, $secret];
$result = \Lavka\source_failure($error, 'connect');
check($result['sqlstate'] === null && $result['mysql_code'] === null, 'Reject non-code fields');
$error = new PDOException('Connection failed');
$error->errorInfo = ['HY000', 2002];
$result = \Lavka\source_failure($error, 'connect', ['Peer certificate CN did not match ' . $secret]);
check($result['reason'] === 'tls_certificate' && strpos(json_encode($result), $secret) === false, 'Suppressed warning classification and redaction');
$dir = sys_get_temp_dir() . '/lavka-source-diag-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
    $config = $dir . '/source.json';
    $contents = json_encode(['prefix' => $secret . ';', 'password' => $secret]);
    file_put_contents($config, $contents);
    chmod($config, 0600);
    $result = \Lavka\diagnose_source($config);
    $expected = PHP_VERSION_ID < 80200 ? 'requires_php82_cli' : 'source_config';
    check($result['reason'] === $expected, 'Configuration failure or CLI compatibility');
    check(strpos(json_encode($result), $secret) === false, 'Config values never leave diagnostic');
    check(file_get_contents($config) === $contents, 'Diagnostic leaves config untouched');
    check(count(scandir($dir)) === 3, 'Diagnostic creates no files');
    if (PHP_VERSION_ID >= 80200) {
        $result = \Lavka\diagnose_source($dir . '/not-present');
        check($result['state'] === 'error' && $result['reason'] === 'source_config', 'Missing config is handled without raw warnings');
    }
} finally {
    unlink($config);
    rmdir($dir);
}
echo json_encode(['source_diagnostics_redaction' => 'passed', 'read_only_config' => 'passed', 'php' => PHP_VERSION]), "\n";
