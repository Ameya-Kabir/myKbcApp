<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_check.php';

$user = admin_gate(true);
$pdo  = db();
$method = $_SERVER['REQUEST_METHOD'];

/** Validate and normalise a question payload; returns [data, error]. */
function clean_question(array $in): array
{
    $text = isset($in['question_text']) && is_string($in['question_text']) ? trim($in['question_text']) : '';
    if ($text === '' || mb_strlen($text) > 1000) {
        return [null, 'Question text is required (max 1000 characters).'];
    }
    $d = ['question_text' => $text];
    foreach (['a', 'b', 'c', 'd'] as $k) {
        $v = isset($in["opt_$k"]) && is_string($in["opt_$k"]) ? trim($in["opt_$k"]) : '';
        if ($v === '' || mb_strlen($v) > 255) {
            return [null, 'Option ' . strtoupper($k) . ' is required (max 255 characters).'];
        }
        $d["opt_$k"] = $v;
    }
    $c = $in['correct_opt'] ?? '';
    if (!is_string($c) || !in_array($c, ['A', 'B', 'C', 'D'], true)) {
        return [null, 'Pick the correct option (A–D).'];
    }
    $d['correct_opt'] = $c;
    $diff = $in['difficulty'] ?? null;
    if (!(is_int($diff) || (is_string($diff) && ctype_digit($diff))) || (int) $diff < 1 || (int) $diff > 3) {
        return [null, 'Difficulty must be 1, 2 or 3.'];
    }
    $d['difficulty'] = (int) $diff;
    return [$d, null];
}

try {
    if ($method === 'GET') {
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 10;
        $q       = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
        $diff    = isset($_GET['difficulty']) ? (int) $_GET['difficulty'] : 0;

        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = 'question_text LIKE :q';
            $params[':q'] = '%' . addcslashes($q, '\\%_') . '%';
        }
        if ($diff >= 1 && $diff <= 3) {
            $where[] = 'difficulty = :d';
            $params[':d'] = $diff;
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM questions $sqlWhere");
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($page, $pages);

        $stmt = $pdo->prepare("SELECT * FROM questions $sqlWhere ORDER BY id DESC LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        json_response(['ok' => true, 'questions' => $stmt->fetchAll(), 'page' => $page, 'pages' => $pages, 'total' => $total]);
    }

    if ($method !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $in     = read_json_body();
    $action = $in['action'] ?? '';

    switch ($action) {
        case 'create':
            [$d, $err] = clean_question($in);
            if ($err) json_response(['ok' => false, 'error' => $err], 422);
            $pdo->prepare(
                'INSERT INTO questions (question_text, opt_a, opt_b, opt_c, opt_d, correct_opt, difficulty)
                 VALUES (:question_text, :opt_a, :opt_b, :opt_c, :opt_d, :correct_opt, :difficulty)'
            )->execute($d);
            json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);

        case 'update':
            $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) json_response(['ok' => false, 'error' => 'Invalid question id.'], 422);
            [$d, $err] = clean_question($in);
            if ($err) json_response(['ok' => false, 'error' => $err], 422);
            $d['id'] = $id;
            $st = $pdo->prepare(
                'UPDATE questions SET question_text = :question_text, opt_a = :opt_a, opt_b = :opt_b,
                 opt_c = :opt_c, opt_d = :opt_d, correct_opt = :correct_opt, difficulty = :difficulty
                 WHERE id = :id'
            );
            $st->execute($d);
            json_response(['ok' => true]);

        case 'delete':
            $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) json_response(['ok' => false, 'error' => 'Invalid question id.'], 422);
            $st = $pdo->prepare('DELETE FROM questions WHERE id = :id');
            $st->execute([':id' => $id]);
            json_response(['ok' => $st->rowCount() > 0, 'error' => $st->rowCount() ? null : 'Question not found.'], $st->rowCount() ? 200 : 404);

        default:
            json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (PDOException $e) {
    error_log('KBC admin_questions error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Database error. Try again.'], 500);
}
