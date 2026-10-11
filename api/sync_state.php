<?php
declare(strict_types=1);

/**
 * Polling endpoint for host and player screens. Called every ~1.5 s, so it stays lean:
 *  - releases the PHP session lock immediately (parallel requests don't queue up),
 *  - one indexed query to decide "unchanged" (client sends the version it already has),
 *  - the full payload (player list etc.) only when the version moved.
 * Never returns answers or any correct_answer.
 */
require_once __DIR__ . '/../includes/room_lib.php';

$user = api_user('GET');
session_write_close();

$pin = $_GET['pin'] ?? null;
if (!valid_pin($pin)) {
    json_response(['ok' => false, 'error' => 'Invalid room PIN.'], 422);
}
$have = isset($_GET['v']) ? (int) $_GET['v'] : -1;

try {
    $stmt = db()->prepare(
        'SELECT s.id, s.host_id, s.status, s.state_version, s.current_question_id,
                EXISTS(SELECT 1 FROM session_players p WHERE p.session_id = s.id AND p.user_id = :u) AS is_player
         FROM sessions s WHERE s.room_pin = :pin'
    );
    $stmt->execute([':u' => $user['id'], ':pin' => $pin]);
    $room = $stmt->fetch();

    if (!$room) {
        json_response(['ok' => false, 'code' => 'no_room', 'error' => 'This room no longer exists.'], 404);
    }
    $isHost = (int) $room['host_id'] === $user['id'];
    if (!$isHost && !(int) $room['is_player']) {
        json_response(['ok' => false, 'code' => 'not_member', 'error' => 'You are not in this room.'], 403);
    }

    $version = (int) $room['state_version'];
    if ($version === $have) {
        json_response(['ok' => true, 'unchanged' => true, 'version' => $version]);
    }

    $pl = db()->prepare(
        'SELECT u.username AS name, sp.score FROM session_players sp
         JOIN users u ON u.id = sp.user_id
         WHERE sp.session_id = :s ORDER BY sp.joined_at, sp.id LIMIT 200'
    );
    $pl->execute([':s' => $room['id']]);
    $players = array_map(fn($r) => ['name' => $r['name'], 'score' => (int) $r['score']], $pl->fetchAll());

    json_response([
        'ok'           => true,
        'unchanged'    => false,
        'version'      => $version,
        'status'       => $room['status'],
        'role'         => $isHost ? 'host' : 'player',
        'players'      => $players,
        'player_count' => count($players),
        'has_question' => $room['current_question_id'] !== null,   // question payload arrives in step 3
    ]);
} catch (PDOException $e) {
    error_log('KBC sync_state error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Temporary server problem.'], 500);
}
