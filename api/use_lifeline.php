<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/game_lib.php';

api_boot('POST');
$in   = api_body();
$type = $in['lifeline_type'] ?? '';
if (!is_string($type) || !in_array($type, GAME_LIFELINES, true)) {
    json_response(['ok' => false, 'error' => 'Unknown lifeline.'], 422);
}

$g = &game();
$q = &require_current($g, $in['question_id'] ?? null);

if ($q['started_at'] === null || question_expired($g, $q)) {
    json_response(['ok' => false, 'error' => 'Time is up for this question.'], 409);
}
$life = &$g['lifelines'][$type];
if (!$life['enabled']) {
    json_response(['ok' => false, 'error' => 'That lifeline is switched off for this game.'], 403);
}
if ($life['remaining'] < 1) {
    json_response(['ok' => false, 'error' => 'No uses left for that lifeline.'], 403);
}
if (in_array($type, $q['used'], true)) {
    json_response(['ok' => false, 'error' => 'You already used that lifeline on this question.'], 409);
}

$letters = ['A', 'B', 'C', 'D'];
$correct = $q['correct'];
$visible = array_values(array_diff($letters, $q['hidden']));   // options still in play

switch ($type) {
    case '5050':
        $wrong = array_values(array_diff($visible, [$correct]));
        shuffle($wrong);
        $hide = array_slice($wrong, 0, 2);
        $q['hidden'] = array_values(array_unique(array_merge($q['hidden'], $hide)));
        $result = ['hide' => $hide];
        break;

    case 'poll':
        $result = ['percentages' => audience_poll($visible, $correct, $q['difficulty'])];
        break;

    case 'expert':
        $result = expert_hint($correct, $q['options'][$correct], $q['difficulty']);
        break;
}

$life['remaining']--;
$q['used'][] = $type;

json_response(['ok' => true, 'lifeline' => $type, 'remaining' => $life['remaining']] + $result);

/**
 * Percentages for the visible options (hidden ones get 0). The correct option always leads;
 * the lead shrinks with difficulty so hard questions look more uncertain.
 */
function audience_poll(array $visible, string $correct, int $difficulty): array
{
    $ranges = [1 => [60, 80], 2 => [50, 70], 3 => [40, 58]];
    [$lo, $hi] = $ranges[$difficulty] ?? $ranges[2];
    $others = array_values(array_diff($visible, [$correct]));
    $k = count($others);

    $pcts = null;
    for ($try = 0; $try < 25 && $k > 0; $try++) {
        $c = random_int($lo, $hi);
        if ($k === 1) {
            $c = max($c, random_int(56, 78));      // a lone rival must still trail
        }
        $weights = array_map(fn() => random_int(1, 3), $others);
        $rest = 100 - $c;
        $shares = array_map(fn($w) => (int) floor($rest * $w / array_sum($weights)), $weights);
        $shares[0] += $rest - array_sum($shares);   // rounding remainder
        if (max($shares) < $c) {
            $pcts = array_combine($others, $shares) + [$correct => $c];
            break;
        }
    }
    if ($pcts === null) {                            // fallback: correct leads, rest split evenly
        $pcts = [$correct => $k ? 100 - intdiv(100 - 60, $k) * $k : 100];
        foreach ($others as $o) {
            $pcts[$o] = intdiv(100 - 60, $k);
        }
    }

    $out = [];
    foreach (['A', 'B', 'C', 'D'] as $L) {
        $out[$L] = $pcts[$L] ?? 0;
    }
    return $out;
}

function expert_hint(string $letter, string $text, int $difficulty): array
{
    $experts = ['Prof. Rao', 'Dr. Mehta', 'Ms. Iyer', 'Mr. Banerjee', 'Dr. Fernandes'];
    $lines = [
        1 => [
            "I'm quite sure about this one. It's option %s, %s.",
            "That's an easy one: go with %s, %s.",
        ],
        2 => [
            "I've come across this before. I'd say option %s, %s.",
            "I'm fairly confident it's %s, %s.",
        ],
        3 => [
            "I'm not completely certain, but I'd lean towards %s, %s.",
            "Tough one. My instinct says %s, %s, though I wouldn't bet everything on it.",
        ],
    ];
    $pool = $lines[$difficulty] ?? $lines[2];
    return [
        'expert' => $experts[array_rand($experts)],
        'hint'   => sprintf($pool[array_rand($pool)], $letter, $text),
    ];
}
