<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/settings.php';

$user = current_user();
if ($user === null) {
    json_response(['ok' => false, 'error' => 'Log in to save settings.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
if (!csrf_verify()) {
    json_response(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 403);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) {
    json_response(['ok' => false, 'error' => 'Invalid request.'], 400);
}

function int_in(array $in, string $k, int $min, int $max): ?int
{
    $v = $in[$k] ?? null;
    if (is_int($v) || (is_string($v) && ctype_digit($v))) {
        $v = (int) $v;
        return ($v >= $min && $v <= $max) ? $v : null;
    }
    return null;
}

$clean = [
    'num_questions' => int_in($in, 'num_questions', 1, 50),
    'timer_seconds' => int_in($in, 'timer_seconds', 5, 120),
];
foreach (['5050', 'poll', 'expert'] as $l) {
    $clean["limit_$l"]    = int_in($in, "limit_$l", 1, 5);
    $clean["lifeline_$l"] = !empty($in["lifeline_$l"]) ? 1 : 0;
}
foreach ($clean as $k => $v) {
    if ($v === null) {
        json_response(['ok' => false, 'error' => 'One of the values is out of range.'], 422);
    }
}

try {
    foreach ($clean as $k => $v) {
        set_setting($user['id'], $k, (string) $v);
    }
} catch (PDOException $e) {
    error_log('KBC save_settings error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Could not save settings. Try again.'], 500);
}
json_response(['ok' => true]);
