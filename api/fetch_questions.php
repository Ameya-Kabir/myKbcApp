<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/game_lib.php';

$user = api_boot('POST');   // POST: it starts a new game and replaces any unfinished one

try {
    $s   = typed_settings($user['id']);
    $stmt = db()->prepare(
        'SELECT id, question_text, opt_a, opt_b, opt_c, opt_d, correct_opt, difficulty
         FROM questions ORDER BY RAND() LIMIT :n'
    );
    $stmt->bindValue(':n', $s['num_questions'], PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('KBC fetch_questions error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Could not load questions. Try again.'], 500);
}

if (!$rows) {
    json_response(['ok' => false, 'error' => 'There are no questions yet. Ask an admin to add some.'], 404);
}

// Easier questions first, random order within a difficulty.
usort($rows, fn($a, $b) => $a['difficulty'] <=> $b['difficulty']);

$game = [
    'id'             => bin2hex(random_bytes(8)),
    'saved'          => false,
    'timer'          => $s['timer_seconds'],
    'points_correct' => $s['points_correct'],
    'points_wrong'   => $s['points_wrong'],
    'cur'            => 0,
    'lifelines'      => [],
    'questions'      => [],
];
foreach ($s['lifelines'] as $k => $l) {
    $game['lifelines'][$k] = ['enabled' => $l['enabled'], 'remaining' => $l['limit']];
}

$out = [];
foreach ($rows as $r) {
    $options = ['A' => $r['opt_a'], 'B' => $r['opt_b'], 'C' => $r['opt_c'], 'D' => $r['opt_d']];
    $game['questions'][] = [
        'id'         => (int) $r['id'],
        'text'       => $r['question_text'],
        'options'    => $options,
        'correct'    => $r['correct_opt'],          // stays in the session
        'difficulty' => (int) $r['difficulty'],
        'started_at' => null,
        'result'     => null,                        // correct | wrong | timeout
        'choice'     => null,
        'delta'      => 0,
        'hidden'     => [],                          // options removed by 50:50
        'used'       => [],                          // lifelines used on this question
    ];
    $out[] = ['id' => (int) $r['id'], 'text' => $r['question_text'], 'options' => $options];
}

$_SESSION['game'] = $game;

json_response([
    'ok'   => true,
    'game' => [
        'total'          => count($out),
        'requested'      => $s['num_questions'],
        'timer'          => $game['timer'],
        'points_correct' => $game['points_correct'],
        'points_wrong'   => $game['points_wrong'],
        'lifelines'      => $game['lifelines'],
    ],
    'questions' => $out,
]);
