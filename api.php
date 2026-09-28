<?php
define('BEAT', true);
require __DIR__ . '/lib/bootstrap.php';

header('Cache-Control: no-store');

function out($data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function fail(string $message, int $status = 400): never {
    out(['error' => $message], $status);
}

function require_user(): array {
    $u = current_user();
    if (!$u) fail('Your session has ended. Sign in again.', 401);
    return $u;
}

function ts(?string $value, string $field): ?string {
    if ($value === null || $value === '') return null;
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Exception) {
        fail("$field is not a valid date.");
    }
}

function project_id($value, int $userId): ?int {
    if ($value === null || $value === '' || (int)$value === 0) return null;
    $ok = q('SELECT 1 FROM projects WHERE id = ? AND user_id = ?', [(int)$value, $userId])->fetchColumn();
    if (!$ok) fail('That project no longer exists.');
    return (int)$value;
}

function timer_row(array $r): array {
    return [
        'id' => (int)$r['id'],
        'project_id' => $r['project_id'] === null ? null : (int)$r['project_id'],
        'description' => $r['description'],
        'notes' => $r['notes'],
        'start_time' => $r['start_time'],
        'end_time' => $r['end_time'],
    ];
}

const TIMER_COLS = "id, project_id, description, notes,
    to_char(start_time, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS start_time,
    to_char(end_time, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS end_time";

function list_timers(int $userId, ?string $from, ?string $to, array $projectIds = []): array {
    $sql = 'SELECT ' . TIMER_COLS . ' FROM timers WHERE user_id = ?';
    $p = [$userId];
    if ($from) { $sql .= ' AND start_time >= ?'; $p[] = $from; }
    if ($to)   { $sql .= ' AND start_time < ?';  $p[] = $to; }
    if ($projectIds) {
        $parts = [];
        foreach ($projectIds as $pid) {
            if ((int)$pid === 0) { $parts[] = 'project_id IS NULL'; }
            else { $parts[] = 'project_id = ?'; $p[] = (int)$pid; }
        }
        $sql .= ' AND (' . implode(' OR ', $parts) . ')';
    }
    $sql .= ' ORDER BY start_time DESC';
    return array_map('timer_row', q($sql, $p)->fetchAll());
}

function list_projects(int $userId): array {
    $rows = q('SELECT id, name, color, archived FROM projects WHERE user_id = ? ORDER BY archived, lower(name)', [$userId])->fetchAll();
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color'], 'archived' => (bool)$r['archived'],
    ], $rows);
}

function get_timer(int $id, int $userId): array {
    $r = q('SELECT ' . TIMER_COLS . ' FROM timers WHERE id = ? AND user_id = ?', [$id, $userId])->fetch();
    if (!$r) fail('That entry no longer exists.', 404);
    return timer_row($r);
}

$method = $_SERVER['REQUEST_METHOD'];
$in = [];
if ($method === 'POST') {
    // Custom header can't be sent cross-site without a CORS preflight, which we never grant.
    if (($_SERVER['HTTP_X_BEAT'] ?? '') !== '1') fail('Bad request.', 403);
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
}
$action = $method === 'POST' ? ($in['action'] ?? '') : ($_GET['action'] ?? '');

try {
    switch ($action) {
    case 'login':
        $ip = client_ip();
        $recent = (int)q("SELECT count(*) FROM login_attempts WHERE ip = ? AND attempted_at > now() - interval '15 minutes'", [$ip])->fetchColumn();
        if ($recent >= 8) fail('Too many failed attempts. Wait 15 minutes and try again.', 429);
        $row = q('SELECT id, password_hash FROM users WHERE username = ?', [trim($in['username'] ?? '')])->fetch();
        if (!$row || !password_verify($in['password'] ?? '', $row['password_hash'])) {
            q('INSERT INTO login_attempts (ip) VALUES (?)', [$ip]);
            usleep(400000);
            fail('Username or password is incorrect.', 401);
        }
        q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        start_session((int)$row['id']);
        out(['ok' => true]);

    case 'logout':
        end_session();
        out(['ok' => true]);

    case 'change_password':
        $u = require_user();
        $hash = q('SELECT password_hash FROM users WHERE id = ?', [$u['id']])->fetchColumn();
        if (!password_verify($in['current'] ?? '', $hash)) fail('Current password is incorrect.');
        $new = $in['new'] ?? '';
        if (strlen($new) < 8) fail('Use at least 8 characters for the new password.');
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        q('DELETE FROM sessions WHERE user_id = ?', [$u['id']]);
        start_session($u['id']);
        out(['ok' => true]);

    case 'state':
        $u = require_user();
        out([
            'user' => $u,
            'projects' => list_projects($u['id']),
            'running' => array_map('timer_row', q('SELECT ' . TIMER_COLS . ' FROM timers WHERE user_id = ? AND end_time IS NULL ORDER BY start_time', [$u['id']])->fetchAll()),
        ]);

    case 'timers':
        $u = require_user();
        $pids = array_filter(explode(',', $_GET['projects'] ?? ''), fn($v) => $v !== '');
        out(['timers' => list_timers($u['id'], ts($_GET['from'] ?? null, 'From'), ts($_GET['to'] ?? null, 'To'), $pids)]);

    case 'timer_start':
        $u = require_user();
        $id = q("INSERT INTO timers (user_id, project_id, description, start_time) VALUES (?, ?, ?, now()) RETURNING id",
            [$u['id'], project_id($in['project_id'] ?? null, $u['id']), trim($in['description'] ?? '')])->fetchColumn();
        out(['timer' => get_timer((int)$id, $u['id'])]);

    case 'timer_stop':
        $u = require_user();
        q('UPDATE timers SET end_time = now() WHERE id = ? AND user_id = ? AND end_time IS NULL', [(int)($in['id'] ?? 0), $u['id']]);
        out(['timer' => get_timer((int)($in['id'] ?? 0), $u['id'])]);

    case 'timer_stop_all':
        $u = require_user();
        q('UPDATE timers SET end_time = now() WHERE user_id = ? AND end_time IS NULL', [$u['id']]);
        out(['ok' => true]);

    case 'timer_save':
        $u = require_user();
        $start = ts($in['start_time'] ?? null, 'Start');
        $end = ts($in['end_time'] ?? null, 'End');
        if (!$start) fail('Start time is required.');
        if ($end && $end < $start) fail('End time must be after the start time.');
        $params = [project_id($in['project_id'] ?? null, $u['id']), trim($in['description'] ?? ''), trim($in['notes'] ?? ''), $start, $end];
        if (!empty($in['id'])) {
            get_timer((int)$in['id'], $u['id']);
            q('UPDATE timers SET project_id = ?, description = ?, notes = ?, start_time = ?, end_time = ? WHERE id = ? AND user_id = ?',
                [...$params, (int)$in['id'], $u['id']]);
            $id = (int)$in['id'];
        } else {
            $id = (int)q('INSERT INTO timers (project_id, description, notes, start_time, end_time, user_id) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                [...$params, $u['id']])->fetchColumn();
        }
        out(['timer' => get_timer($id, $u['id'])]);

    case 'timer_delete':
        $u = require_user();
        q('DELETE FROM timers WHERE id = ? AND user_id = ?', [(int)($in['id'] ?? 0), $u['id']]);
        out(['ok' => true]);

    case 'project_save':
        $u = require_user();
        $name = trim($in['name'] ?? '');
        if ($name === '') fail('Give the project a name.');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $in['color'] ?? '') ? $in['color'] : '#2f5fd0';
        $archived = !empty($in['archived']);
        if (!empty($in['id'])) {
            q('UPDATE projects SET name = ?, color = ?, archived = ? WHERE id = ? AND user_id = ?', [$name, $color, $archived ? 't' : 'f', (int)$in['id'], $u['id']]);
        } else {
            q('INSERT INTO projects (user_id, name, color, archived) VALUES (?, ?, ?, ?)', [$u['id'], $name, $color, $archived ? 't' : 'f']);
        }
        out(['projects' => list_projects($u['id'])]);

    case 'project_delete':
        $u = require_user();
        q('DELETE FROM projects WHERE id = ? AND user_id = ?', [(int)($in['id'] ?? 0), $u['id']]);
        out(['projects' => list_projects($u['id'])]);

    case 'export':
        $u = require_user();
        $pids = array_filter(explode(',', $_GET['projects'] ?? ''), fn($v) => $v !== '');
        $timers = array_reverse(list_timers($u['id'], ts($_GET['from'] ?? null, 'From'), ts($_GET['to'] ?? null, 'To'), $pids));
        $names = array_column(list_projects($u['id']), 'name', 'id');
        $tz = new DateTimeZone(in_array($_GET['tz'] ?? '', DateTimeZone::listIdentifiers(), true) ? $_GET['tz'] : 'UTC');
        $fmt = fn($t) => $t ? (new DateTimeImmutable($t))->setTimezone($tz)->format('Y-m-d H:i:s') : '';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="beat-' . date('Y-m-d') . '.csv"');
        $f = fopen('php://output', 'w');
        fputcsv($f, ['Project', 'Description', 'Start', 'End', 'Hours', 'Notes']);
        foreach ($timers as $t) {
            $end = $t['end_time'] ?? gmdate('Y-m-d\TH:i:s\Z');
            $hours = (strtotime($end) - strtotime($t['start_time'])) / 3600;
            fputcsv($f, [$names[$t['project_id']] ?? '', $t['description'], $fmt($t['start_time']), $fmt($t['end_time']), number_format($hours, 2, '.', ''), $t['notes']]);
        }
        exit;

    default:
        fail('Unknown action.', 404);
    }
} catch (PDOException $e) {
    error_log('beat: ' . $e->getMessage());
    fail('The server could not save that. Try again.', 500);
}
