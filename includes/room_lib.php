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

// ---------------------------------------------------------------- question helpers

/** A–D options if the question is multiple-choice (all four present), else null. */
function mcq_options(?string $json): ?array
{
    $o = $json !== null ? json_decode($json, true) : null;
    if (!is_array($o)) {
        return null;
    }
    $out = [];
    foreach (['A', 'B', 'C', 'D'] as $L) {
        if (!isset($o[$L]) || !is_string($o[$L]) || $o[$L] === '') {
            return null;
        }
        $out[$L] = $o[$L];
    }
    return $out;
}

/** Collapse whitespace and spacing around commas (so "a, b" matches "a,b"); optionally ignore case. */
function normalize_answer(string $s, bool $caseSensitive): string
{
    $s = preg_replace('/\s+/u', ' ', trim($s)) ?? $s;
    $s = preg_replace('/\s*,\s*/u', ',', $s) ?? $s;
    return $caseSensitive ? $s : mb_strtolower($s);
}

/** Is $answer right for question row $q (needs options_json, correct_answer, case_sensitive)? */
function grade_answer(array $q, string $answer): bool
{
    if (mcq_options($q['options_json']) !== null) {
        return strtoupper(trim($answer)) === strtoupper($q['correct_answer']);
    }
    $cs = (int) $q['case_sensitive'] === 1;
    return hash_equals(normalize_answer($q['correct_answer'], $cs), normalize_answer($answer, $cs));
}

/** Record that this room has asked a question (idempotent). */
function mark_asked(int $sessionId, int $questionId): void
{
    db()->prepare('INSERT IGNORE INTO session_questions (session_id, question_id) VALUES (:s, :q)')
        ->execute([':s' => $sessionId, ':q' => $questionId]);
}

/**
 * Put a question on screen for a room: open for answers, answer hidden, countdown starting now
 * (DB clock, so the server alone decides how much time is left). The memory game (F) starts its
 * countdown later, at "Hide & Ask", so the timer never eats the memorising time.
 */
function activate_question(PDO $pdo, int $sessionId, int $questionId, bool $startGame = false): void
{
    $t = $pdo->prepare('SELECT q_type FROM questions WHERE id = :id');
    $t->execute([':id' => $questionId]);
    $isMemory = $t->fetchColumn() === 'F';

    $pdo->prepare('UPDATE sessions SET ' . ($startGame ? "status = 'active', " : '') . '
                   current_question_id = :q, question_open = 1, answer_revealed = 0, reveal_stage = 0,
                   question_started_at = ' . ($isMemory ? 'NULL' : 'NOW(3)') . ',
                   state_version = state_version + 1 WHERE id = :id')
        ->execute([':q' => $questionId, ':id' => $sessionId]);
    mark_asked($sessionId, $questionId);
}
