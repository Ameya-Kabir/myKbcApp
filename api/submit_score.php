<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/game_lib.php';

$user = api_boot('POST');
$g    = &game();

// Everything is totalled from the server's own records; the request body is ignored.
$correct = $wrong = $timeouts = $answered = 0;
$review  = [];
foreach ($g['questions'] as $q) {
    if ($q['result'] !== null) {
        $answered++;
        match ($q['result']) {
            'correct' => $correct++,
            'wrong'   => $wrong++,
            'timeout' => $timeouts++,
        };
    }
    $review[] = [
        'id'          => $q['id'],
        'text'        => $q['text'],
        'options'     => $q['options'],
        'result'      => $q['result'] ?? 'skipped',
        'choice'      => $q['choice'],
        'correct_opt' => $q['correct'],     // the game is over, so it is safe to reveal
        'points'      => $q['delta'],
    ];
}
$total = $correct * $g['points_correct'] - $wrong * $g['points_wrong'];

if ($answered === 0) {
    unset($_SESSION['game']);
    json_response(['ok' => false, 'error' => 'Answer at least one question before finishing a game.'], 422);
}

if (!$g['saved']) {
    try {
        db()->prepare(
            'INSERT INTO scores (user_id, total_points, correct_answers, wrong_answers)
             VALUES (:u, :t, :c, :w)'
        )->execute([':u' => $user['id'], ':t' => $total, ':c' => $correct, ':w' => $wrong]);
    } catch (PDOException $e) {
        error_log('KBC submit_score error: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'Could not save your score. Try again.'], 500);
    }
    $g['saved'] = true;     // a repeat call returns the same summary without a second insert
}

json_response([
    'ok'           => true,
    'total_points' => $total,
    'correct'      => $correct,
    'wrong'        => $wrong,
    'timeouts'     => $timeouts,
    'unanswered'   => count($g['questions']) - $answered,
    'review'       => $review,
]);
