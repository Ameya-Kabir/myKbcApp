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
            $clean = array_filter([
                'points_correct' => $in['points_correct'] ?? null,
                'points_wrong'   => $in['points_wrong'] ?? null,
                'timer_seconds'  => $in['timer_seconds'] ?? null,
            ], fn($v) => $v !== null && $v !== '');
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
            activate_question($pdo, (int) $room['id'], (int) $qid, true);
            $pdo->commit();
            json_response(['ok' => true]);

        case 'list_questions':
            require_host_room($user, $in['pin'] ?? null);
            $rows = $pdo->query('SELECT id, q_type, question_text FROM questions ORDER BY id LIMIT 500')->fetchAll();
            json_response(['ok' => true, 'questions' => array_map(fn($r) => [
                'id' => (int) $r['id'], 'type' => $r['q_type'], 'text' => mb_substr($r['question_text'], 0, 90),
            ], $rows)]);

        case 'next_question':
        case 'set_question':
            $room = require_host_room($user, $in['pin'] ?? null);
            if ($room['status'] !== 'active') {
                json_response(['ok' => false, 'error' => 'Start the game first.'], 409);
            }
            $qid = $in['question_id'] ?? null;
            if ($qid === null || $qid === '') {
                // No id given: random question not yet asked in this room.
                $r = $pdo->prepare('SELECT id FROM questions
                                    WHERE id NOT IN (SELECT question_id FROM session_questions WHERE session_id = :s)
                                    ORDER BY RAND() LIMIT 1');
                $r->execute([':s' => $room['id']]);
                $qid = $r->fetchColumn();
                if ($qid === false) {
                    // Question bank exhausted: the game is over, show the podium.
                    $pdo->prepare("UPDATE sessions SET status = 'finished', question_open = 0,
                                   state_version = state_version + 1 WHERE id = :id")->execute([':id' => $room['id']]);
                    json_response(['ok' => true, 'finished' => true]);
                }
            } else {
                $chk = $pdo->prepare('SELECT id FROM questions WHERE id = :id');
                $chk->execute([':id' => filter_var($qid, FILTER_VALIDATE_INT) ?: 0]);
                $qid = $chk->fetchColumn();
                if ($qid === false) {
                    json_response(['ok' => false, 'error' => 'That question does not exist.'], 404);
                }
            }
            activate_question($pdo, (int) $room['id'], (int) $qid);
            json_response(['ok' => true, 'question_id' => (int) $qid]);

        case 'show_leaderboard':
            $room = require_host_room($user, $in['pin'] ?? null);
            if ($room['status'] !== 'active') {
                json_response(['ok' => false, 'error' => 'The game is not running.'], 409);
            }
            // "No current question" is the between-questions state: every screen shows standings.
            $pdo->prepare('UPDATE sessions SET current_question_id = NULL, question_open = 0, answer_revealed = 0,
                           question_started_at = NULL, reveal_stage = 0,
                           state_version = state_version + 1 WHERE id = :id')->execute([':id' => $room['id']]);
            json_response(['ok' => true]);

        case 'close_question':
            $room = require_host_room($user, $in['pin'] ?? null);
            if ($room['status'] !== 'active' || $room['current_question_id'] === null) {
                json_response(['ok' => false, 'error' => 'There is no open question.'], 409);
            }
            if ((int) $room['answer_revealed'] === 1) {
                json_response(['ok' => false, 'error' => 'The answer has already been revealed.'], 409);
            }
            // Closing stops answers only. Players still do not learn the answer until reveal_answer.
            $pdo->prepare('UPDATE sessions SET question_open = 0, state_version = state_version + 1 WHERE id = :id')
                ->execute([':id' => $room['id']]);
            json_response(['ok' => true]);

        case 'reveal_answer':
            $room = require_host_room($user, $in['pin'] ?? null);
            if ($room['status'] !== 'active' || $room['current_question_id'] === null) {
                json_response(['ok' => false, 'error' => 'There is no current question.'], 409);
            }
            // Revealing also closes submissions, so nobody can answer after seeing the result.
            $st = $pdo->prepare('UPDATE sessions SET question_open = 0, answer_revealed = 1, state_version = state_version + 1
                                 WHERE id = :id AND answer_revealed = 0');
            $st->execute([':id' => $room['id']]);
            json_response(['ok' => true, 'already' => $st->rowCount() === 0]);

        case 'update_reveal_stage':
            $room = require_host_room($user, $in['pin'] ?? null);
            $stage = filter_var($in['stage'] ?? null, FILTER_VALIDATE_INT);
            if ($room['status'] !== 'active' || $room['current_question_id'] === null) {
                json_response(['ok' => false, 'error' => 'There is no current question.'], 409);
            }
            if ((int) $room['question_open'] !== 1 || (int) $room['answer_revealed'] === 1) {
                json_response(['ok' => false, 'error' => 'This question is already closed.'], 409);
            }
            $t = $pdo->prepare('SELECT q_type FROM questions WHERE id = :id');
            $t->execute([':id' => $room['current_question_id']]);
            $t_type = $t->fetchColumn();
            $max = ['C' => 3, 'F' => 1][$t_type] ?? null;   // C: 0/25/50/100%  F: show/hide
            if ($max === null) {
                json_response(['ok' => false, 'error' => 'This question type has no reveal stages.'], 409);
            }
            if ($stage === false || $stage < 0 || $stage > $max) {
                json_response(['ok' => false, 'error' => "Stage must be 0 to $max."], 422);
            }
            // Memory game: the answering countdown starts when the items are hidden ("Hide & Ask").
            $startClock = ($t_type === 'F' && $stage >= 1 && (int) $room['reveal_stage'] < 1) ? ', question_started_at = NOW(3)' : '';
            $pdo->prepare("UPDATE sessions SET reveal_stage = :st$startClock, state_version = state_version + 1 WHERE id = :id")
                ->execute([':st' => $stage, ':id' => $room['id']]);
            json_response(['ok' => true]);

        case 'end_game':
            $room = require_host_room($user, $in['pin'] ?? null);
            $pdo->prepare("UPDATE sessions SET status = 'finished', question_open = 0, state_version = state_version + 1 WHERE id = :id")
                ->execute([':id' => $room['id']]);
            json_response(['ok' => true]);

        case 'reset_room':
            // "Start new session": same room and players, fresh game.
            $room = require_host_room($user, $in['pin'] ?? null);
            if ($room['status'] !== 'finished') {
                json_response(['ok' => false, 'error' => 'Finish the current game first.'], 409);
            }
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM answers WHERE session_id = :id')->execute([':id' => $room['id']]);
            $pdo->prepare('DELETE FROM session_questions WHERE session_id = :id')->execute([':id' => $room['id']]);
            $pdo->prepare('UPDATE session_players SET score = 0 WHERE session_id = :id')->execute([':id' => $room['id']]);
            $pdo->prepare("UPDATE sessions SET status = 'lobby', current_question_id = NULL, question_open = 0,
                           answer_revealed = 0, question_started_at = NULL,
                           reveal_stage = 0, state_version = state_version + 1 WHERE id = :id")->execute([':id' => $room['id']]);
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
