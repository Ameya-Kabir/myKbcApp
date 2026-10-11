<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/room_lib.php';

$user = api_user('POST');
$in   = read_json_body();
$pin  = is_string($in['pin'] ?? null) ? preg_replace('/\s+/', '', $in['pin']) : '';

// Slow down PIN guessing (per session, same approach as login).
$_SESSION['pin_fails'] = array_filter($_SESSION['pin_fails'] ?? [], fn($t) => $t > time() - 300);
if (count($_SESSION['pin_fails']) >= 10) {
    json_response(['ok' => false, 'error' => 'Too many wrong PINs. Wait a few minutes and try again.'], 429);
}
if (!valid_pin($pin)) {
    json_response(['ok' => false, 'error' => 'Enter the 6-digit room PIN.'], 422);
}

try {
    $room = find_room($pin);
    if ($room === null || $room['status'] === 'finished') {
        $_SESSION['pin_fails'][] = time();
        json_response(['ok' => false, 'error' => 'No open room has that PIN. Check it with the host.'], 404);
    }
    $sid = (int) $room['id'];

    if ((int) $room['host_id'] === $user['id']) {
        json_response(['ok' => true, 'redirect' => 'host.php?pin=' . $pin]);   // you run this room
    }

    $already = is_room_member($sid, $user['id']);
    if (!$already) {
        if ($room['status'] !== 'lobby') {
            json_response(['ok' => false, 'error' => 'That game has already started.'], 409);
        }
        $cnt = db()->prepare('SELECT COUNT(*) FROM session_players WHERE session_id = :s');
        $cnt->execute([':s' => $sid]);
        if ((int) $cnt->fetchColumn() >= ROOM_MAX_PLAYERS) {
            json_response(['ok' => false, 'error' => 'This room is full.'], 409);
        }
        $ins = db()->prepare('INSERT IGNORE INTO session_players (session_id, user_id) VALUES (:s, :u)');
        $ins->execute([':s' => $sid, ':u' => $user['id']]);
        if ($ins->rowCount() > 0) {
            bump_version($sid);
        }
    }
    json_response(['ok' => true, 'redirect' => 'play.php?pin=' . $pin]);
} catch (PDOException $e) {
    error_log('KBC player_join error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Could not join the room. Try again.'], 500);
}
