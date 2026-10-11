<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/room_lib.php';

const ANSWER_SLACK_SECONDS = 2;   // grace for network latency on top of the question timer

$user = api_user('POST');
$in   = read_json_body();
$pin  = $in['pin'] ?? null;
$ans  = $in['answer'] ?? null;

if (!valid_pin($pin)) {
    json_response(['ok' => false, 'error' => 'Invalid room PIN.'], 422);
}
if (!is_string($ans) || trim($ans) === '' || mb_strlen($ans) > 255) {
    json_response(['ok' => false, 'error' => 'Enter an answer (up to 255 characters).'], 422);
}
$ans = trim($ans);

$pdo = db();
try {
    $pdo->beginTransaction();

    // Row lock: a submission and the host's "close" can never interleave.
    $stmt = $pdo->prepare('SELECT id, host_id, status, question_open, answer_revealed, reveal_stage, current_question_id,
                IF(question_started_at IS NULL, NULL, TIMESTAMPDIFF(MICROSECOND, question_started_at, NOW(3)) / 1000000) AS elapsed
         FROM sessions WHERE room_pin = :p FOR UPDATE');
    $stmt->execute([':p' => $pin]);
    $room = $stmt->fetch();

    $fail = function (string $msg, int $code, string $c = '') use ($pdo): never {
        $pdo->rollBack();
        json_response(['ok' => false, 'code' => $c, 'error' => $msg], $code);
    };

    if (!$room) {
        $fail('This room no longer exists.', 404, 'no_room');
    }
    $sid = (int) $room['id'];
    if ((int) $room['host_id'] === $user['id'] || !is_room_member($sid, $user['id'])) {
        $fail('Only players in this room can answer.', 403, 'not_member');
    }
    if ($room['status'] !== 'active' || $room['current_question_id'] === null) {
        $fail('There is no question to answer right now.', 409);
    }
    if ((int) $room['question_open'] !== 1 || (int) $room['answer_revealed'] === 1) {
        $fail('Answers are closed for this question.', 409, 'closed');
    }

    $qs = $pdo->prepare('SELECT q_type, options_json, correct_answer, case_sensitive FROM questions WHERE id = :id');
    $qs->execute([':id' => $room['current_question_id']]);
    $q = $qs->fetch();
    if (!$q) {
        $fail('This question is no longer available.', 409);
    }
    // Memory game: answers are only accepted once the host has hidden the items (reveal_stage 1).
    if ($q['q_type'] === 'F' && (int) $room['reveal_stage'] < 1) {
        $fail('Wait for the host to hide the items, then type what you remember.', 409, 'phase');
    }
    if (mcq_options($q['options_json']) !== null && !in_array(strtoupper($ans), ['A', 'B', 'C', 'D'], true)) {
        $fail('Choose A, B, C or D.', 422);
    }

    $rules = get_session_settings($sid);

    // Server-side clock: the countdown plus a little network slack. Anything later is rejected.
    if ($room['elapsed'] === null || (float) $room['elapsed'] > $rules['timer_seconds'] + ANSWER_SLACK_SECONDS) {
        $fail("Time's up! Your answer came in too late.", 409, 'late');
    }
    $correct = grade_answer($q, $ans);
    $delta   = $correct ? $rules['points_correct'] : -$rules['points_wrong'];

    try {
        $pdo->prepare(
            'INSERT INTO answers (session_id, question_id, user_id, answer_text, is_correct, points_awarded)
             VALUES (:s, :q, :u, :a, :c, :p)'
        )->execute([':s' => $sid, ':q' => $room['current_question_id'], ':u' => $user['id'],
                    ':a' => $ans, ':c' => $correct ? 1 : 0, ':p' => $delta]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {     // unique (session, question, user): already answered
            $fail('You have already answered this question.', 409, 'already');
        }
        throw $e;
    }

    $pdo->prepare('UPDATE session_players SET score = score + :d WHERE session_id = :s AND user_id = :u')
        ->execute([':d' => $delta, ':s' => $sid, ':u' => $user['id']]);

    // Wake the polling clients: the host's "players answered" counter reads from this.
    $pdo->prepare('UPDATE sessions SET state_version = state_version + 1 WHERE id = :id')->execute([':id' => $sid]);

    $pdo->commit();
    // Correctness is deliberately not returned: players find out when the host closes the question.
    json_response(['ok' => true, 'locked' => true]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('KBC submit_answer error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Could not save your answer. Try again.'], 500);
}
