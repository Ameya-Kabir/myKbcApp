<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/room_lib.php';

$user   = api_user('POST');
$in     = read_json_body();
$action = $in['action'] ?? '';
$pdo    = db();

try {
    switch ($action) {
        case 'create_room':
            // normalize_session_settings treats a missing key as "use default", so drop nulls first.
            $clean = array_filter(['points_correct' => $in['points_correct'] ?? null, 'points_wrong' => $in['points_wrong'] ?? null], fn($v) => $v !== null && $v !== '');
            try {
                $settings = normalize_session_settings($clean);
            } catch (InvalidArgumentException $e) {
                json_response(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            $pdo->beginTransaction();
            // A host runs one room at a time: close any earlier open one.
            $pdo->prepare("UPDATE sessions SET status = 'finished', question_open = 0, state_version = state_version + 1
                           WHERE host_id = :h AND status <> 'finished'")->execute([':h' => $user['id']]);

            $sid = 0;
            $pin = '';
            for ($i = 0; $i < 10 && !$sid; $i++) {
                $pin = (string) random_int(100000, 999999);
                try {
                    $pdo->prepare('INSERT INTO sessions (host_id, room_pin) VALUES (:h, :p)')->execute([':h' => $user['id'], ':p' => $pin]);
                    $sid = (int) $pdo->lastInsertId();
                } catch (PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) !== 1062) {   // 1062 = duplicate PIN, try another
                        throw $e;
                    }
                }
            }
            if (!$sid) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'Could not allocate a room PIN. Try again.'], 500);
            }
            create_session_settings($sid, $settings);
            $pdo->commit();
            json_response(['ok' => true, 'pin' => $pin, 'redirect' => 'host.php?pin=' . $pin]);

        case 'start_game':
            $room = require_host_room($user, $in['pin'] ?? null);
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT status FROM sessions WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => $room['id']]);
            if ($lock->fetchColumn() !== 'lobby') {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'This game has already started.'], 409);
            }
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM session_players WHERE session_id = :id');
            $cnt->execute([':id' => $room['id']]);
            if ((int) $cnt->fetchColumn() < 1) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'Wait for at least one player to join.'], 409);
            }
            $qid = $pdo->query('SELECT id FROM questions ORDER BY RAND() LIMIT 1')->fetchColumn();
            if ($qid === false) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'The question bank is empty. Add questions first.'], 409);
            }
            $pdo->prepare("UPDATE sessions SET status = 'active', current_question_id = :q, question_open = 1,
                           reveal_stage = 0, state_version = state_version + 1 WHERE id = :id")
                ->execute([':q' => $qid, ':id' => $room['id']]);
            $pdo->commit();
            json_response(['ok' => true]);

        default:
            json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('KBC host_controls error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Database error. Try again.'], 500);
}
