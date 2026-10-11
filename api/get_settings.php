<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/game_lib.php';

$user = api_boot('GET');

try {
    $s = typed_settings($user['id']);
} catch (PDOException $e) {
    error_log('KBC get_settings error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Could not load settings.'], 500);
}
json_response(['ok' => true, 'settings' => $s]);
