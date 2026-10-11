<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
if (!csrf_verify()) {
    json_response(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 403);
}

$body   = json_decode(file_get_contents('php://input') ?: '', true);
$input  = is_array($body) ? $body : $_POST;
$action = $input['action'] ?? '';

function str_field(array $in, string $k): string
{
    return isset($in[$k]) && is_string($in[$k]) ? trim($in[$k]) : '';
}

/** Log the user in: fresh session id + fresh CSRF token. */
function establish_session(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']  = (int) $u['id'];
    $_SESSION['username'] = $u['username'];
    $_SESSION['is_admin'] = (bool) $u['is_admin'];
    $_SESSION['csrf']     = bin2hex(random_bytes(32));
}

try {
    switch ($action) {
        case 'register':
            $username = str_field($input, 'username');
            $email    = strtolower(str_field($input, 'email'));
            $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';

            if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
                json_response(['ok' => false, 'field' => 'username', 'error' => 'Username must be 3–30 letters, numbers or underscores.'], 422);
            }
            if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_response(['ok' => false, 'field' => 'email', 'error' => 'Enter a valid email address.'], 422);
            }
            if (strlen($password) < 8 || strlen($password) > 72) {
                json_response(['ok' => false, 'field' => 'password', 'error' => 'Password must be 8–72 characters.'], 422);
            }

            $pdo = db();
            $chk = $pdo->prepare('SELECT username, email FROM users WHERE username = :u OR email = :e LIMIT 1');
            $chk->execute([':u' => $username, ':e' => $email]);
            if ($row = $chk->fetch()) {
                $dupUser = strcasecmp($row['username'], $username) === 0;
                json_response([
                    'ok' => false,
                    'field' => $dupUser ? 'username' : 'email',
                    'error' => $dupUser ? 'That username is taken.' : 'An account with that email already exists.',
                ], 409);
            }

            $ins = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
            $ins->execute([':u' => $username, ':e' => $email, ':p' => password_hash($password, PASSWORD_DEFAULT)]);

            establish_session(['id' => $pdo->lastInsertId(), 'username' => $username, 'is_admin' => 0]);
            json_response(['ok' => true, 'redirect' => 'dashboard.php', 'csrf' => $_SESSION['csrf']]);

        case 'login':
            $login    = str_field($input, 'login');
            $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';

            // Crude per-session throttle against rapid guessing.
            $_SESSION['fails'] = $_SESSION['fails'] ?? [];
            $_SESSION['fails'] = array_filter($_SESSION['fails'], fn($t) => $t > time() - 300);
            if (count($_SESSION['fails']) >= 5) {
                json_response(['ok' => false, 'error' => 'Too many attempts. Wait a few minutes and try again.'], 429);
            }

            $stmt = db()->prepare('SELECT id, username, password_hash, is_admin FROM users WHERE username = :l OR email = :l2 LIMIT 1');
            $stmt->execute([':l' => $login, ':l2' => strtolower($login)]);
            $u = $stmt->fetch();

            // Always run password_verify so response time doesn't reveal whether the account exists.
            $hash  = $u['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
            $valid = password_verify($password, $hash) && $u;

            if (!$valid) {
                $_SESSION['fails'][] = time();
                json_response(['ok' => false, 'error' => 'Username or password is incorrect.'], 401);
            }
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                    ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $u['id']]);
            }
            unset($_SESSION['fails']);
            establish_session($u);
            json_response(['ok' => true, 'redirect' => 'dashboard.php', 'csrf' => $_SESSION['csrf']]);

        case 'logout':
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            json_response(['ok' => true, 'redirect' => 'index.php']);

        default:
            json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (PDOException $e) {
    error_log('KBC auth error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Something went wrong on our side. Try again shortly.'], 500);
}
