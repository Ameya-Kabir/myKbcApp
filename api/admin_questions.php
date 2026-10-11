<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_check.php';
require_once __DIR__ . '/../includes/room_lib.php';   // mcq_options(), settings constants

admin_gate(true);
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

/** http(s) URL or a site-relative assets/ path. '' = none, false = invalid. */
function clean_media_url(mixed $v): string|false
{
    $v = is_string($v) ? trim($v) : '';
    if ($v === '') {
        return '';
    }
    if (mb_strlen($v) > 500) {
        return false;
    }
    if (preg_match('#^https?://#i', $v) && filter_var($v, FILTER_VALIDATE_URL)) {
        return $v;
    }
    if (preg_match('#^assets/[A-Za-z0-9_\-./]+$#', $v) && !str_contains($v, '..')) {
        return $v;
    }
    return false;
}

/**
 * Validate a question payload from the editor and map it onto the v2 columns.
 * Conventions (see database_v2.sql): D keeps its audio in media_url; E keeps the audio in
 * options_json.audio; F keeps its items in options_json.items; MCQ options are A–D keys.
 * Returns [row for INSERT/UPDATE, error].
 */
function clean_question(array $in): array
{
    $type = $in['q_type'] ?? '';
    if (!is_string($type) || !in_array($type, KBC_QUESTION_TYPES, true)) {
        return [null, 'Choose a question type.'];
    }
    $mode = match ($type) {
        'A1' => 'mcq',
        'A2', 'F' => 'text',
        default => $in['answer_mode'] ?? '',
    };
    if (!in_array($mode, ['mcq', 'text'], true)) {
        return [null, 'Choose an answer format (multiple choice or typed).'];
    }

    $text = is_string($in['question_text'] ?? null) ? trim($in['question_text']) : '';
    if ($text === '' || mb_strlen($text) > 1000) {
        return [null, 'Question text is required (max 1000 characters).'];
    }

    // Media
    $media = '';
    if (in_array($type, ['B', 'C', 'D', 'E', 'F'], true)) {
        $media = clean_media_url($in['media_url'] ?? '');
        if ($media === false) {
            return [null, 'Media must be an http(s) URL or a path starting with assets/.'];
        }
        if ($media === '' && $type !== 'F') {
            return [null, $type === 'D' ? 'An audio URL is required for a music round.' : 'An image URL is required for this type.'];
        }
    }
    $options = [];
    if ($type === 'E') {
        $audio = clean_media_url($in['audio_url'] ?? '');
        if ($audio === false || $audio === '') {
            return [null, 'An audio URL (http(s) or assets/ path) is required for picture + music.'];
        }
        $options['audio'] = $audio;
    }

    // Answer
    $correct = '';
    if ($mode === 'mcq') {
        $opts = [];
        foreach (['A', 'B', 'C', 'D'] as $L) {
            $v = is_string($in['opt_' . strtolower($L)] ?? null) ? trim($in['opt_' . strtolower($L)]) : '';
            if ($v === '' || mb_strlen($v) > 255) {
                return [null, "Option $L is required (max 255 characters)."];
            }
            $opts[$L] = $v;
        }
        $correct = is_string($in['correct_answer'] ?? null) ? strtoupper(trim($in['correct_answer'])) : '';
        if (!in_array($correct, ['A', 'B', 'C', 'D'], true)) {
            return [null, 'Pick the correct option (A–D).'];
        }
        $options = $opts + $options;
    } else {
        $correct = is_string($in['correct_answer'] ?? null) ? trim($in['correct_answer']) : '';
    }

    if ($type === 'F') {
        $raw   = $in['items'] ?? '';
        $items = is_array($raw) ? $raw : preg_split('/\R/u', (string) $raw);
        $items = array_values(array_filter(array_map(fn($i) => trim((string) $i), $items), fn($i) => $i !== ''));
        if (count($items) < 2 || count($items) > 12) {
            return [null, 'Add 2–12 items to memorise, one per line.'];
        }
        foreach ($items as $i) {
            if (mb_strlen($i) > 60) {
                return [null, 'Each item can be at most 60 characters.'];
            }
        }
        $options['items'] = $items;
        if ($correct === '') {
            $correct = implode(',', $items);   // default: players type the items back in order
        }
    }
    if ($correct === '' || mb_strlen($correct) > 255) {
        return [null, 'The correct answer is required (max 255 characters).'];
    }

    return [[
        'q_type'         => $type,
        'question_text'  => $text,
        'media_url'      => $media === '' ? null : $media,
        'options_json'   => $options ? json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'correct_answer' => $correct,
        'case_sensitive' => ($mode === 'text' && !empty($in['case_sensitive'])) ? 1 : 0,
    ], null];
}

/** Flatten a DB row into the fields the editor form uses. */
function to_editor_row(array $r): array
{
    $o      = $r['options_json'] !== null ? (json_decode($r['options_json'], true) ?: []) : [];
    $isMcq  = mcq_options($r['options_json']) !== null;
    return [
        'id'             => (int) $r['id'],
        'q_type'         => $r['q_type'],
        'question_text'  => $r['question_text'],
        'media_url'      => $r['media_url'] ?? '',
        'audio_url'      => is_string($o['audio'] ?? null) ? $o['audio'] : '',
        'answer_mode'    => $isMcq ? 'mcq' : 'text',
        'opt_a'          => $o['A'] ?? '', 'opt_b' => $o['B'] ?? '', 'opt_c' => $o['C'] ?? '', 'opt_d' => $o['D'] ?? '',
        'items'          => is_array($o['items'] ?? null) ? implode("\n", $o['items']) : '',
        'correct_answer' => $r['correct_answer'],
        'case_sensitive' => (int) $r['case_sensitive'],
    ];
}

try {
    if ($method === 'GET') {
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 10;
        $q       = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
        $type    = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';

        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = 'question_text LIKE :q';
            $params[':q'] = '%' . addcslashes($q, '\\%_') . '%';
        }
        if (in_array($type, KBC_QUESTION_TYPES, true)) {
            $where[] = 'q_type = :t';
            $params[':t'] = $type;
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM questions $sqlWhere");
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($page, $pages);

        $stmt = $pdo->prepare("SELECT * FROM questions $sqlWhere ORDER BY id DESC LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        json_response(['ok' => true, 'questions' => array_map('to_editor_row', $stmt->fetchAll()), 'page' => $page, 'pages' => $pages, 'total' => $total]);
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
                'INSERT INTO questions (q_type, question_text, media_url, options_json, correct_answer, case_sensitive)
                 VALUES (:q_type, :question_text, :media_url, :options_json, :correct_answer, :case_sensitive)'
            )->execute($d);
            json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);

        case 'update':
            $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) json_response(['ok' => false, 'error' => 'Invalid question id.'], 422);
            [$d, $err] = clean_question($in);
            if ($err) json_response(['ok' => false, 'error' => $err], 422);
            $d['id'] = $id;
            $pdo->prepare(
                'UPDATE questions SET q_type = :q_type, question_text = :question_text, media_url = :media_url,
                 options_json = :options_json, correct_answer = :correct_answer, case_sensitive = :case_sensitive
                 WHERE id = :id'
            )->execute($d);
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
