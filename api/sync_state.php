<?php
declare(strict_types=1);

/**
 * Polling endpoint for host and player screens (~every 1.5 s), so it stays lean:
 *  - releases the PHP session lock immediately,
 *  - stage 1: one indexed query; if the client's version is current it replies {"unchanged":true},
 *  - stage 2 (only when something moved): question, scores, this player's answer.
 *
 * Question phases (computed here, from the server clock): open -> closed -> revealed.
 *   open     accepting answers
 *   closed   timer ran out, everyone answered, or the host closed it; the answer is STILL hidden
 *   revealed the host pressed "Reveal answer"
 * SECURITY: correct_answer reaches PHP only once answer_revealed = 1 or when the caller is the host
 * (who sees it in `host_only`). A player's payload never contains it before the host reveals it, and scores shown during an open
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
        'SELECT s.id, s.host_id, s.status, s.state_version, s.question_open, s.answer_revealed, s.reveal_stage,
                s.current_question_id, COALESCE(st.timer_seconds, ' . (int) KBC_SESSION_DEFAULTS['timer_seconds'] . ') AS timer_seconds,
                IF(s.question_started_at IS NULL, NULL, TIMESTAMPDIFF(MICROSECOND, s.question_started_at, NOW(3)) / 1000000) AS elapsed,
                EXISTS(SELECT 1 FROM session_players p WHERE p.session_id = s.id AND p.user_id = :u) AS is_player
         FROM sessions s LEFT JOIN session_settings st ON st.session_id = s.id
         WHERE s.room_pin = :pin'
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

    $version  = (int) $room['state_version'];
    $sid      = (int) $room['id'];
    $qid      = $room['current_question_id'] !== null ? (int) $room['current_question_id'] : null;
    $open     = (int) $room['question_open'] === 1;       // host/DB flag: still accepting answers
    $revealed = (int) $room['answer_revealed'] === 1;
    $active   = $room['status'] !== 'lobby';
    $timerSec = (int) $room['timer_seconds'];

    // Seconds left on the countdown (server clock). Null when no countdown is running, e.g. the
    // memory game while the items are still on screen.
    $remaining = null;
    if ($room['status'] === 'active' && $qid !== null && $open && !$revealed && $room['elapsed'] !== null) {
        $remaining = max(0.0, round($timerSec - (float) $room['elapsed'], 1));
    }

    // Joins, host actions and every submitted answer bump the version. Idle polls still carry the
    // clock so every screen's countdown re-anchors to the server (a few bytes).
    if ($version === $haveVersion) {
        $quick = ['ok' => true, 'unchanged' => true, 'version' => $version];
        if ($remaining !== null) {
            $quick['remaining_time'] = $remaining;
        }
        json_response($quick);
    }

    // Until the host reveals, hide the points the current question has earned so far (a moving
    // leaderboard would leak who is right). A finished game shows everything.
    $hidePoints = $room['status'] === 'active' && $qid !== null && !$revealed;

    // Players (+ scores)
    $pl = $pdo->prepare(
        'SELECT u.username AS name, a.id IS NOT NULL AS did_answer,
                sp.score - IF(:hide = 1, COALESCE(a.points_awarded, 0), 0) AS score
         FROM session_players sp
         JOIN users u ON u.id = sp.user_id
         LEFT JOIN answers a ON a.session_id = sp.session_id AND a.question_id = :q AND a.user_id = sp.user_id
         WHERE sp.session_id = :s
         ORDER BY ' . ($active ? 'score DESC, sp.id' : 'sp.joined_at, sp.id') . ' LIMIT 200'
    );
    $pl->execute([':hide' => $hidePoints ? 1 : 0, ':q' => $qid, ':s' => $sid]);
    $players = [];
    $rank = 0;
    $prevScore = null;
    foreach ($pl->fetchAll() as $i => $r) {
        $score = (int) $r['score'];
        if ($score !== $prevScore) {      // rows arrive sorted by score, so a new score starts a new rank
            $rank = $i + 1;
            $prevScore = $score;
        }
        $p = ['name' => $r['name'], 'score' => $score];
        if ($active) {
            $p['rank'] = $rank;
        }
        if ($isHost && $active) {
            $p['answered'] = (bool) $r['did_answer'];
        }
        $players[] = $p;
    }

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM answers WHERE session_id = :s AND question_id = :q');
    $cnt->execute([':s' => $sid, ':q' => $qid]);
    $answered = (int) $cnt->fetchColumn();

    $total = count($players);
    $phase = null;
    if ($room['status'] === 'active' && $qid !== null) {
        $phase = $revealed ? 'revealed'
            : ((!$open || ($remaining !== null && $remaining <= 0) || ($total > 0 && $answered >= $total)) ? 'closed' : 'open');
    }

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
        'phase'          => $phase,                 // open | closed | revealed | null
        'question_open'  => $phase === 'open',
        'answer_revealed' => $revealed,
        'timer_seconds'  => $timerSec,
        'remaining_time' => $remaining,
        'current_question' => null,
    ];

    if ($room['status'] === 'active' && $qid !== null) {
        $q = $pdo->prepare(
            'SELECT q_type, question_text, media_url, options_json,
                    IF(:revealed = 1 OR :ishost = 1, correct_answer, NULL) AS correct_answer
             FROM questions WHERE id = :id'
        );
        $q->execute([':revealed' => $revealed ? 1 : 0, ':ishost' => $isHost ? 1 : 0, ':id' => $qid]);
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

            // Memory game: how many boxes to draw. Only the count; the items themselves follow below.
            if ($row['q_type'] === 'F' && is_array($extra['items'] ?? null)) {
                $out['current_question']['item_count'] = count($extra['items']);
            }

            // Memory game: the items exist in the payload only during phase 1 (stage 0, question open).
            // From "Hide & Ask" onward nobody receives them, so they can't be read back from the network.
            if ($row['q_type'] === 'F' && $phase === 'open' && (int) $room['reveal_stage'] === 0 && is_array($extra['items'] ?? null)) {
                $out['current_question']['items'] = array_values(array_map('strval', $extra['items']));
            }

            if (!$isHost) {
                $mine = $pdo->prepare(
                    'SELECT answer_text, is_correct, points_awarded FROM answers
                     WHERE session_id = :s AND question_id = :q AND user_id = :u'
                );
                $mine->execute([':s' => $sid, ':q' => $qid, ':u' => $user['id']]);
                if ($a = $mine->fetch()) {
                    $out['my_answer'] = ['submitted' => true, 'answer' => $a['answer_text']];
                    if ($revealed) {   // right/wrong and points are told only after the host reveals
                        $out['my_answer'] += ['correct' => (bool) $a['is_correct'], 'points' => (int) $a['points_awarded']];
                    }
                } else {
                    $out['my_answer'] = ['submitted' => false];
                }
            }
            if ($revealed) {         // host pressed "Reveal answer": everyone gets it
                $out['reveal'] = ['correct_answer' => $row['correct_answer']];
            } elseif ($isHost) {     // before that, only the host's own payload carries the answer
                $out['host_only'] = ['correct_answer' => $row['correct_answer']];
            }
        }
    }

    // Game over: each player gets their own placing and stats, ready to render.
    if ($room['status'] === 'finished' && !$isHost) {
        $mine = null;
        foreach ($players as $p) {
            if ($p['name'] === $user['username']) {
                $mine = $p;
                break;
            }
        }
        $st = $pdo->prepare('SELECT COALESCE(SUM(is_correct), 0) AS c, COALESCE(SUM(1 - is_correct), 0) AS w
                             FROM answers WHERE session_id = :s AND user_id = :u');
        $st->execute([':s' => $sid, ':u' => $user['id']]);
        $stats = $st->fetch();
        $asked = $pdo->prepare('SELECT COUNT(*) FROM session_questions WHERE session_id = :s');
        $asked->execute([':s' => $sid]);
        $askedN = (int) $asked->fetchColumn();

        if ($mine) {
            $sameRank = count(array_filter($players, fn($p) => $p['rank'] === $mine['rank']));
            $out['summary'] = [
                'rank'      => $mine['rank'],
                'of'        => count($players),
                'score'     => $mine['score'],
                'tied'      => $sameRank > 1,
                'correct'   => (int) $stats['c'],
                'wrong'     => (int) $stats['w'],
                'no_answer' => max(0, $askedN - (int) $stats['c'] - (int) $stats['w']),
                'questions' => $askedN,
            ];
        }
    }

    json_response($out);
} catch (PDOException $e) {
    error_log('KBC sync_state error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Temporary server problem.'], 500);
}
