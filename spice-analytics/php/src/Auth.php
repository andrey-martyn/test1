<?php

declare (strict_types=1);
namespace Lavka;

require_once __DIR__ . '/common.php';
final class Auth
{
    private $db;
    private $file;
    public function __construct(string $directory)
    {
        $this->file = $directory . '/access-php.json';
        $this->db = database($directory . '/sessions-php.sqlite3');
        $this->db->exec('CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY,expires INTEGER,revision TEXT);
            CREATE TABLE IF NOT EXISTS attempts(client TEXT,moment INTEGER);CREATE INDEX IF NOT EXISTS attempts_moment ON attempts(moment);');
    }
    public static function setPassword(string $directory, string $password): void
    {
        if (mb_strlen($password) < 12 || strlen($password) > 512) {
            throw new \InvalidArgumentException('Пароль должен содержать минимум 12 символов, максимум 512 байт');
        }
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new \RuntimeException('Для безопасного входа нужен Argon2id в PHP');
        }
        $hash = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 32768, 'time_cost' => 3, 'threads' => 1]);
        if (!$hash || password_get_info($hash)['algoName'] !== 'argon2id') throw new \RuntimeException('Argon2id недоступен');
        atomic_write($directory . '/access-php.json', encode(['hash' => $hash, 'secret' => bin2hex(random_bytes(32))]));
    }
    public function configured(): bool
    {
        return is_file($this->file);
    }
    public function valid(?string $token): bool
    {
        if (!$token || strlen($token) > 128 || !$this->configured()) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT expires,revision FROM sessions WHERE token=?');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        return $row && $row['expires'] > time() && hash_equals($row['revision'], hash_file('sha256', $this->file));
    }
    public function login(string $password, string $ip): string
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Сначала настройте пароль кабинета');
        }
        $credentials = decode(file_get_contents($this->file));
        $client = hash_hmac('sha256', $ip, $credentials['secret']);
        $now = time();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->db->prepare('DELETE FROM attempts WHERE moment<?');
            $stmt->execute([$now - 300]);
            $stmt = $this->db->prepare('DELETE FROM sessions WHERE expires<?');
            $stmt->execute([$now]);
            $stmt = $this->db->prepare('SELECT count(*) FROM attempts WHERE client=?');
            $stmt->execute([$client]);
            if ((int) $stmt->fetchColumn() >= 10 || (int) $this->db->query('SELECT count(*) FROM attempts')->fetchColumn() >= 200) {
                throw new \DomainException('rate_limit');
            }
            if (!password_verify($password, $credentials['hash'])) {
                $stmt = $this->db->prepare('INSERT INTO attempts VALUES(?,?)');
                $stmt->execute([$client, $now]);
                $this->db->exec('COMMIT');
                throw new \DomainException('password');
            }
            $stmt = $this->db->prepare('DELETE FROM attempts WHERE client=?');
            $stmt->execute([$client]);
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $stmt = $this->db->prepare('INSERT INTO sessions VALUES(?,?,?)');
            $stmt->execute([hash('sha256', $token), $now + 43200, hash_file('sha256', $this->file)]);
            $this->db->exec('COMMIT');
            return $token;
        } catch (\Throwable $e) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }
    }
    public function logout(string $token): void
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE token=?');
        $stmt->execute([hash('sha256', $token)]);
    }
}
