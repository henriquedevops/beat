<?php
if (!defined('BEAT')) { http_response_code(404); exit; }

const SESSION_COOKIE = 'beat_session';

function config(): array {
    static $cfg = null;
    return $cfg ??= require __DIR__ . '/config.php';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = config();
        $pdo = new PDO($c['db_dsn'], $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET TIME ZONE 'UTC'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function set_session_cookie(string $value, int $expires): void {
    setcookie(SESSION_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/beat/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function current_user(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    $user = null;
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token !== '') {
        $row = q('SELECT u.id, u.username FROM sessions s JOIN users u ON u.id = s.user_id
                  WHERE s.token_hash = ? AND s.expires_at > now()', [hash('sha256', $token)])->fetch();
        if ($row) $user = ['id' => (int)$row['id'], 'username' => $row['username']];
    }
    return $user;
}

function start_session(int $userId): void {
    $token = bin2hex(random_bytes(32));
    $days = config()['session_days'];
    q("INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?, ?, now() + (? || ' days')::interval)",
      [hash('sha256', $token), $userId, $days]);
    q('DELETE FROM sessions WHERE expires_at < now()');
    set_session_cookie($token, time() + $days * 86400);
}

function end_session(): void {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token !== '') q('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]);
    set_session_cookie('', time() - 3600);
}
