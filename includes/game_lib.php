<?php
declare(strict_types=1);

/**
 * Shared helpers for the game APIs.
 *
 * Active game lives in $_SESSION['game'] (server-side only):
 *   id, saved, timer, points_correct, points_wrong, cur (index of next unanswered question),
 *   lifelines => [type => ['enabled' => bool, 'remaining' => int]],
 *   questions => [ [id, text, options[A..D], correct, difficulty, started_at, result, choice,
 *                   delta, hidden[], used[]] ... ]
 * The correct answer never leaves the server until the question has been answered.
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';

const GAME_TIMER_GRACE = 3;   // seconds of network slack on top of the configured timer
const GAME_LIFELINES   = ['5050', 'poll', 'expert'];

/** Authenticate an API call: login, HTTP method, CSRF (POST). Returns the user. */
function api_boot(string $method = 'POST'): array
{
    $user = current_user();
    if ($user === null) {
        json_response(['ok' => false, 'error' => 'Log in to play.'], 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    if ($method === 'POST' && !csrf_verify()) {
        json_response(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 403);
    }
    return $user;
}

function api_body(): array
{
    $b = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($b) ? $b : [];
}

function clamp_int(mixed $v, int $min, int $max): int
{
    return max($min, min($max, (int) $v));
}

/** Typed, merged (defaults < global < user) settings for the frontend and for game snapshots. */
function typed_settings(int $userId): array
{
    $s = get_settings($userId);
    $lifelines = [];
    foreach (GAME_LIFELINES as $k) {
        $enabled = $s["lifeline_$k"] === '1';
        $lifelines[$k] = ['enabled' => $enabled, 'limit' => $enabled ? clamp_int($s["limit_$k"], 1, 5) : 0];
    }
    return [
        'num_questions'  => clamp_int($s['num_questions'], 1, 50),
        'timer_seconds'  => clamp_int($s['timer_seconds'], 5, 120),
        'points_correct' => clamp_int($s['points_correct'], 0, 1000),
        'points_wrong'   => clamp_int($s['points_wrong'], 0, 1000),
        'lifelines'      => $lifelines,
    ];
}

/** Fail unless an active game exists; returns by reference so callers can mutate session state. */
function &game(): array
{
    if (empty($_SESSION['game']) || !is_array($_SESSION['game'])) {
        json_response(['ok' => false, 'error' => 'No active game. Start a new one from the lobby.'], 409);
    }
    return $_SESSION['game'];
}

/** The question the player must answer next, or null when all are answered. */
function &current_question(array &$g): ?array
{
    $null = null;
    if (!isset($g['questions'][$g['cur']])) {
        return $null;
    }
    return $g['questions'][$g['cur']];
}

function question_expired(array $g, array $q): bool
{
    return $q['started_at'] === null || (microtime(true) - $q['started_at']) > $g['timer'] + GAME_TIMER_GRACE;
}

/** Check the question_id the client sent is the current, unanswered one. */
function require_current(array &$g, mixed $qid): array
{
    $q = &current_question($g);
    if ($q === null || $q['result'] !== null) {
        json_response(['ok' => false, 'error' => 'This game has no more questions to answer.'], 409);
    }
    if (!is_int($qid) && !(is_string($qid) && ctype_digit($qid))) {
        json_response(['ok' => false, 'error' => 'Invalid question id.'], 422);
    }
    if ((int) $qid !== $q['id']) {
        json_response(['ok' => false, 'error' => 'That is not the current question.'], 409);
    }
    return $q;
}
