<?php
// Expects: $pageTitle (string), $bodyClass (string), $base (path prefix, '' or '../'), optional $user.
require_once __DIR__ . '/../config/session.php';
$base      = $base ?? '';
$pageTitle = $pageTitle ?? 'KBC Quiz';
$bodyClass = $bodyClass ?? '';
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Marcellus&family=Mulish:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e($base) ?>assets/css/style.css">
<?php foreach (($extraCss ?? []) as $c): ?>
<link rel="stylesheet" href="<?= e($base . $c) ?>">
<?php endforeach; ?>
</head>
<body class="<?= e($bodyClass) ?>" data-base="<?= e($base) ?>">
<?php if (!empty($user)): ?>
<header class="topbar">
    <a class="brand" href="<?= e($base) ?>dashboard.php">KBC Quiz</a>
    <nav>
        <span class="who"><?= e($user['username']) ?></span>
        <?php if ($user['is_admin']): ?><a class="link" href="<?= e($base) ?>admin/index.php">Admin</a><?php endif; ?>
        <button type="button" class="link" id="logout-btn">Log out</button>
    </nav>
</header>
<?php endif; ?>
