<?php
declare(strict_types=1);

/** Shared helpers for the multiplayer room APIs. */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';

const ROOM_MAX_PLAYERS = 100;

/** Authenticate an API call: login, HTTP method, CSRF on POST. Returns the user. */
function api_user(string $method = 'POST'): array
{
    $user = current_user();
    if ($user === null) {
        json_response(['ok' => false, 'code' => 'auth', 'error' => 'Log in to continue.'], 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    if ($method === 'POST' && !csrf_verify()) {
        json_response(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 403);
    }
    return $user;
}

if (!function_exists('read_json_body')) {
    function read_json_body(): array
    {
        $b = json_decode(file_get_contents('php://input') ?: '', true);
        return is_array($b) ? $b : [];
    }
}

function valid_pin(mixed $pin): bool
{
    return is_string($pin) && preg_match('/^\d{6}$/', $pin) === 1;
}

/** Room row by PIN, or null. */
function find_room(string $pin): ?array
{
    $stmt = db()->prepare('SELECT * FROM sessions WHERE room_pin = :p');
    $stmt->execute([':p' => $pin]);
    return $stmt->fetch() ?: null;
}

/** Room row for a host-only action; JSON-fails if the PIN is unknown or the caller is not the host. */
function require_host_room(array $user, mixed $pin): array
{
    if (!valid_pin($pin)) {
        json_response(['ok' => false, 'error' => 'Invalid room PIN.'], 422);
    }
    $room = find_room($pin);
    if ($room === null) {
        json_response(['ok' => false, 'code' => 'no_room', 'error' => 'That room no longer exists.'], 404);
    }
    if ((int) $room['host_id'] !== $user['id']) {
        json_response(['ok' => false, 'error' => 'Only the host can do that.'], 403);
    }
    return $room;
}

function is_room_member(int $sessionId, int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM session_players WHERE session_id = :s AND user_id = :u');
    $stmt->execute([':s' => $sessionId, ':u' => $userId]);
    return (bool) $stmt->fetchColumn();
}

/** Tell every polling client something changed. */
function bump_version(int $sessionId): void
{
    db()->prepare('UPDATE sessions SET state_version = state_version + 1 WHERE id = :id')->execute([':id' => $sessionId]);
}

/** "482913" => "482 913" */
function format_pin(string $pin): string
{
    return substr($pin, 0, 3) . ' ' . substr($pin, 3);
}
