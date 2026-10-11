<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Question types the engine understands (mirrors questions.q_type). */
const KBC_QUESTION_TYPES = ['A1', 'A2', 'B', 'C', 'D', 'E', 'F'];

const KBC_SESSION_STATUSES = ['lobby', 'active', 'finished'];

/** Player screens poll sync_state.php this often (ms). */
const KBC_POLL_INTERVAL_MS = 1500;

/** Defaults for a new room; the host can change them when creating it. */
const KBC_SESSION_DEFAULTS = [
    'points_correct' => 100,
    'points_wrong'   => 0,    // deducted from the score on a wrong answer
];

const KBC_POINTS_MAX = 1000;

/** Validate host-supplied settings, filling gaps from the defaults. Throws on bad input. */
function normalize_session_settings(array $in = []): array
{
    $out = KBC_SESSION_DEFAULTS;
    foreach (array_keys(KBC_SESSION_DEFAULTS) as $k) {
        if (!array_key_exists($k, $in)) {
            continue;
        }
        $v = $in[$k];
        if (!(is_int($v) || (is_string($v) && ctype_digit($v))) || (int) $v < 0 || (int) $v > KBC_POINTS_MAX) {
            throw new InvalidArgumentException("$k must be a whole number from 0 to " . KBC_POINTS_MAX . '.');
        }
        $out[$k] = (int) $v;
    }
    return $out;
}

/** Create the settings row for a room (call inside the room-creation transaction). */
function create_session_settings(int $sessionId, array $in = []): void
{
    $s = normalize_session_settings($in);
    db()->prepare('INSERT INTO session_settings (session_id, points_correct, points_wrong) VALUES (:sid, :c, :w)')
        ->execute([':sid' => $sessionId, ':c' => $s['points_correct'], ':w' => $s['points_wrong']]);
}

/** Points rules for a room, falling back to the defaults if the row is missing. */
function get_session_settings(int $sessionId): array
{
    $stmt = db()->prepare('SELECT points_correct, points_wrong FROM session_settings WHERE session_id = :sid');
    $stmt->execute([':sid' => $sessionId]);
    $row = $stmt->fetch();
    return $row
        ? ['points_correct' => (int) $row['points_correct'], 'points_wrong' => (int) $row['points_wrong']]
        : KBC_SESSION_DEFAULTS;
}
