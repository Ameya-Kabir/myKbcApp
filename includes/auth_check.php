<?php
declare(strict_types=1);

// Include at the top of any page that needs a logged-in user.
require_once __DIR__ . '/../config/session.php';

$user = current_user();
if ($user === null) {
    header('Location: ' . ($base ?? '') . 'index.php');
    exit;
}

/** Call from admin pages after including this file. */
function require_admin(): void
{
    global $user, $base;
    if (!$user['is_admin']) {
        http_response_code(403);
        exit('Admins only.');
    }
}
