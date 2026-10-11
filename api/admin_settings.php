<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_check.php';
require_once __DIR__ . '/../config/settings.php';

admin_gate(true);

/** key => [min, max] for numeric settings; lifeline toggles handled separately. */
const ADMIN_NUMERIC = [
    'points_correct' => [1, 1000],
    'points_wrong'   => [0, 1000],
    'timer_seconds'  => [5, 120],
    'num_questions'  => [1, 50],
    'limit_5050'     => [1, 5],
    'limit_poll'     => [1, 5],
    'limit_expert'   => [1, 5],
];
const ADMIN_TOGGLES = ['lifeline_5050', 'lifeline_poll', 'lifeline_expert'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['ok' => true, 'settings' => get_settings(null)]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $in = read_json_body();
    $clean = [];
    foreach (ADMIN_NUMERIC as $k => [$min, $max]) {
        $v = $in[$k] ?? null;
        if (!(is_int($v) || (is_string($v) && ctype_digit($v))) || (int) $v < $min || (int) $v > $max) {
            json_response(['ok' => false, 'error' => "$k must be a whole number from $min to $max."], 422);
        }
        $clean[$k] = (string) (int) $v;
    }
    foreach (ADMIN_TOGGLES as $k) {
        $clean[$k] = !empty($in[$k]) ? '1' : '0';
    }

    $pdo = db();
    $pdo->beginTransaction();
    foreach ($clean as $k => $v) {
        set_setting(null, $k, $v);
    }
    $pdo->commit();
    json_response(['ok' => true]);
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('KBC admin_settings error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Database error. Try again.'], 500);
}
