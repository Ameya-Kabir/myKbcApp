<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

/**
 * Gate for admin pages and APIs. Checks the session AND re-reads is_admin
 * from the database, so a demoted admin loses access immediately.
 * Pages: non-admins are redirected to the dashboard (guests to login).
 * APIs ($api = true): JSON 401/403 instead, plus CSRF check on non-GET requests.
 */
function admin_gate(bool $api = false): array
{
    $user = current_user();

    if ($user !== null) {
        $stmt = db()->prepare('SELECT is_admin FROM users WHERE id = :id');
        $stmt->execute([':id' => $user['id']]);
        $isAdmin = (int) $stmt->fetchColumn() === 1;
        $_SESSION['is_admin'] = $isAdmin;
        $user['is_admin'] = $isAdmin;
    }

    if ($user === null || !$user['is_admin']) {
        if ($api) {
            json_response(['ok' => false, 'error' => $user === null ? 'Log in first.' : 'Admins only.'], $user === null ? 401 : 403);
        }
        header('Location: ../' . ($user === null ? 'index.php' : 'index.php'));
        exit;
    }

    if ($api && $_SERVER['REQUEST_METHOD'] !== 'GET' && !csrf_verify()) {
        json_response(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 403);
    }
    return $user;
}

if (!function_exists('read_json_body')) {
    function read_json_body(): array
    {
        $b = json_decode(file_get_contents('php://input') ?: '', true);
        return is_array($b) ? $b : [];
    }
}
