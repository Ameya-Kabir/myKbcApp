<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/game_lib.php';

api_boot('POST');
$in = api_body();
$g  = &game();
$q  = &require_current($g, $in['question_id'] ?? null);

switch ($in['action'] ?? '') {
    // The client calls this the moment the countdown begins; the server clock is authoritative.
    case 'start':
        if ($q['started_at'] === null) {
            $q['started_at'] = microtime(true);
        }
        json_response(['ok' => true, 'timer' => $g['timer']]);

    case 'answer':
        if ($q['started_at'] === null) {
            json_response(['ok' => false, 'error' => 'The question has not started.'], 409);
        }
        $choice = $in['choice'] ?? null;
        if ($choice !== null && !(is_string($choice) && in_array($choice, ['A', 'B', 'C', 'D'], true))) {
            json_response(['ok' => false, 'error' => 'Choose A, B, C or D.'], 422);
        }
        if (is_string($choice) && in_array($choice, $q['hidden'], true)) {
            json_response(['ok' => false, 'error' => 'That option was removed by 50:50.'], 422);
        }

        // No answer, or an answer that arrived after time ran out, scores nothing.
        if ($choice === null || question_expired($g, $q)) {
            $q['result'] = 'timeout';
            $q['choice'] = null;
            $q['delta']  = 0;
        } elseif ($choice === $q['correct']) {
            $q['result'] = 'correct';
            $q['choice'] = $choice;
            $q['delta']  = $g['points_correct'];
        } else {
            $q['result'] = 'wrong';
            $q['choice'] = $choice;
            $q['delta']  = -$g['points_wrong'];
        }
        $g['cur']++;

        $total = array_sum(array_column($g['questions'], 'delta'));
        json_response([
            'ok'            => true,
            'result'        => $q['result'],
            'correct_opt'   => $q['correct'],
            'points_delta'  => $q['delta'],
            'total_points'  => $total,
            'has_next'      => isset($g['questions'][$g['cur']]),
        ]);

    default:
        json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}
