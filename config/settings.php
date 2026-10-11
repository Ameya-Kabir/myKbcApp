<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Hard-coded fallbacks used when a key is missing from game_settings. */
const KBC_DEFAULT_SETTINGS = [
    'points_correct'  => '100',
    'points_wrong'    => '50',   // deducted on a wrong answer
    'timer_seconds'   => '30',
    'num_questions'   => '10',
    'lifeline_5050'   => '1',
    'lifeline_poll'   => '1',
    'lifeline_expert' => '1',
    'limit_5050'      => '1',
    'limit_poll'      => '1',
    'limit_expert'    => '1',
];

/**
 * Settings for a user: defaults < global (user_id NULL) < user overrides.
 * Pass null for the global settings only.
 */
function get_settings(?int $userId = null): array
{
    $settings = KBC_DEFAULT_SETTINGS;

    $stmt = db()->prepare(
        'SELECT setting_key, setting_value FROM game_settings
         WHERE user_id IS NULL OR user_id = :uid
         ORDER BY (user_id IS NULL) DESC'   // global first, user overrides last
    );
    $stmt->execute([':uid' => $userId]);
    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists($row['setting_key'], KBC_DEFAULT_SETTINGS)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings;
}

/** Insert or update one setting (global when $userId is null). Key must be whitelisted. */
function set_setting(?int $userId, string $key, string $value): void
{
    if (!array_key_exists($key, KBC_DEFAULT_SETTINGS)) {
        throw new InvalidArgumentException('Unknown setting key.');
    }
    $pdo = db();
    $sel = $pdo->prepare('SELECT id FROM game_settings WHERE setting_key = :k AND user_id <=> :uid LIMIT 1');
    $sel->execute([':k' => $key, ':uid' => $userId]);
    $id = $sel->fetchColumn();

    if ($id !== false) {
        $pdo->prepare('UPDATE game_settings SET setting_value = :v WHERE id = :id')
            ->execute([':v' => $value, ':id' => $id]);
    } else {
        $pdo->prepare('INSERT INTO game_settings (user_id, setting_key, setting_value) VALUES (:uid, :k, :v)')
            ->execute([':uid' => $userId, ':k' => $key, ':v' => $value]);
    }
}
