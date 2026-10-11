<?php
declare(strict_types=1);

/**
 * Polling endpoint for host and player screens (~every 1.5 s), so it stays lean:
 *  - releases the PHP session lock immediately,
 *  - stage 1: one indexed query; if the client's version is current it replies {"unchanged":true},
 *  - stage 2 (only when something moved): question, scores, this player's answer.
 *
 * SECURITY: correct_answer reaches PHP only when the question is closed (question_open = 0) or the
 * caller is the host (who sees it in `host_only`). A player's payload never contains it while a
 * question is open, and scores shown during an open
 * question exclude the points earned on that question (otherwise the leaderboard would leak
 * who is right).
 */
require_once __DIR__ . '/../includes/room_lib.php';

$user = api_user('GET');
session_write_close();

$pin = $_GET['pin'] ?? null;
if (!valid_pin($pin)) {
    json_response(['ok' => false, 'error' => 'Invalid room PIN.'], 422);
}
$haveVersion  = isset($_GET['v']) ? (int) $_GET['v'] : -1;

try {
    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT s.id, s.host_id, s.status, s.state_version, s.question_open, s.reveal_stage, s.current_question_id,
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
    // Joins, host actions and every submitted answer bump the version.
    if ($version === $haveVersion) {
        json_response(['ok' => true, 'unchanged' => true, 'version' => $version]);
    }

    $sid    = (int) $room['id'];
    $qid    = $room['current_question_id'] !== null ? (int) $room['current_question_id'] : null;
    $open   = (int) $room['question_open'] === 1;
    $active = $room['status'] !== 'lobby';

    // Players (+ scores). While a question is open, hide the points it has earned so far.
    $pl = $pdo->prepare(
        'SELECT u.username AS name, a.id IS NOT NULL AS did_answer,
                sp.score - IF(:open = 1, COALESCE(a.points_awarded, 0), 0) AS score
         FROM session_players sp
         JOIN users u ON u.id = sp.user_id
         LEFT JOIN answers a ON a.session_id = sp.session_id AND a.question_id = :q AND a.user_id = sp.user_id
         WHERE sp.session_id = :s
         ORDER BY ' . ($active ? 'score DESC, sp.id' : 'sp.joined_at, sp.id') . ' LIMIT 200'
    );
    $pl->execute([':open' => $open ? 1 : 0, ':q' => $qid, ':s' => $sid]);
    $players = [];
    foreach ($pl->fetchAll() as $r) {
        $p = ['name' => $r['name'], 'score' => (int) $r['score']];
        if ($isHost && $active) {
            $p['answered'] = (bool) $r['did_answer'];
        }
        $players[] = $p;
    }

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM answers WHERE session_id = :s AND question_id = :q');
    $cnt->execute([':s' => $sid, ':q' => $qid]);
    $answered = (int) $cnt->fetchColumn();

    $out = [
        'ok'             => true,
        'unchanged'      => false,
        'version'        => $version,
        'status'         => $room['status'],
        'role'           => $isHost ? 'host' : 'player',
        'me'             => $user['username'],
        'players'        => $players,
        'player_count'   => count($players),
        'total_players'  => count($players),
        'players_answered' => $answered,
        'question_open'  => $open,
        'current_question' => null,
    ];

    if ($active && $qid !== null) {
        $q = $pdo->prepare(
            'SELECT q_type, question_text, media_url, options_json,
                    IF(:closed = 1 OR :ishost = 1, correct_answer, NULL) AS correct_answer
             FROM questions WHERE id = :id'
        );
        $q->execute([':closed' => $open ? 0 : 1, ':ishost' => $isHost ? 1 : 0, ':id' => $qid]);
        if ($row = $q->fetch()) {
            $opts = mcq_options($row['options_json']);
            // D keeps its audio in media_url, E keeps it in options_json.audio; only that key is read,
            // so nothing else from options_json (items, answers) is exposed here.
            $extra = $row['options_json'] !== null ? (json_decode($row['options_json'], true) ?: []) : [];
            $isMusic = $row['q_type'] === 'D';
            $audio = $isMusic ? $row['media_url'] : (is_string($extra['audio'] ?? null) ? $extra['audio'] : null);
            $image = $isMusic ? null : $row['media_url'];
            $out['current_question'] = [
                'id'           => $qid,
                'type'         => $row['q_type'],
                'text'         => $row['question_text'],
                'media'        => $image,     // picture URL (B, C, E, F)
                'audio'        => $audio,     // audio URL (D, E)
                'input'        => $opts !== null ? 'choice' : 'text',
                'options'      => $opts,
                'reveal_stage' => (int) $room['reveal_stage'],
            ];

            if (!$isHost) {
                $mine = $pdo->prepare(
                    'SELECT answer_text, is_correct, points_awarded FROM answers
                     WHERE session_id = :s AND question_id = :q AND user_id = :u'
                );
                $mine->execute([':s' => $sid, ':q' => $qid, ':u' => $user['id']]);
                if ($a = $mine->fetch()) {
                    $out['my_answer'] = ['submitted' => true, 'answer' => $a['answer_text']];
                    if (!$open) {
                        $out['my_answer'] += ['correct' => (bool) $a['is_correct'], 'points' => (int) $a['points_awarded']];
                    }
                } else {
                    $out['my_answer'] = ['submitted' => false];
                }
            }
            if (!$open) {            // host closed the question: everyone gets the reveal
                $out['reveal'] = ['correct_answer' => $row['correct_answer']];
            } elseif ($isHost) {     // open: only the host's own payload carries the answer
                $out['host_only'] = ['correct_answer' => $row['correct_answer']];
            }
        }
    }

    json_response($out);
} catch (PDOException $e) {
    error_log('KBC sync_state error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Temporary server problem.'], 500);
}
