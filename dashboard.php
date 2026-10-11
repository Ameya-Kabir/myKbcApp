<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/config/settings.php';

$s = get_settings($user['id']);

$lb = db()->query(
    'SELECT u.username, MAX(sc.total_points) AS best, COUNT(*) AS games
     FROM scores sc JOIN users u ON u.id = sc.user_id
     GROUP BY u.id, u.username
     ORDER BY best DESC, games ASC
     LIMIT 10'
)->fetchAll();

$mine = db()->prepare('SELECT total_points, correct_answers, wrong_answers, played_at FROM scores WHERE user_id = :u ORDER BY played_at DESC LIMIT 1');
$mine->execute([':u' => $user['id']]);
$last = $mine->fetch();

$lifelines = [
    '5050'   => ['50:50', 'Removes two wrong answers'],
    'poll'   => ['Audience poll', 'Shows how the audience voted'],
    'expert' => ['Ask the expert', 'Get a hint from the expert'],
];

$pageTitle = 'KBC Quiz – Lobby';
$bodyClass = 'page-dashboard';
require __DIR__ . '/includes/header.php';
?>
<main class="lobby">
    <section class="panel config-panel">
        <h1>Set up your game</h1>
        <form id="settings-form" novalidate>
            <div class="row-2">
                <label>Questions
                    <input type="number" name="num_questions" min="1" max="50" value="<?= (int) $s['num_questions'] ?>" required>
                </label>
                <label>Seconds per question
                    <input type="number" name="timer_seconds" min="5" max="120" value="<?= (int) $s['timer_seconds'] ?>" required>
                </label>
            </div>

            <fieldset>
                <legend>Lifelines</legend>
                <?php foreach ($lifelines as $k => [$name, $desc]): ?>
                <div class="lifeline">
                    <label class="switch">
                        <input type="checkbox" name="lifeline_<?= $k ?>" <?= $s["lifeline_$k"] === '1' ? 'checked' : '' ?>>
                        <span class="track" aria-hidden="true"></span>
                        <span class="lf-text"><strong><?= e($name) ?></strong><small><?= e($desc) ?></small></span>
                    </label>
                    <label class="uses">Uses
                        <input type="number" name="limit_<?= $k ?>" min="1" max="5" value="<?= (int) $s["limit_$k"] ?>">
                    </label>
                </div>
                <?php endforeach; ?>
            </fieldset>

            <p class="form-msg" id="settings-msg" role="status" hidden></p>
            <div class="actions">
                <button type="submit" class="btn btn-ghost">Save settings</button>
                <button type="button" class="btn btn-gold" id="play-btn" data-href="game.php">Start game</button>
            </div>
        </form>
        <p class="hint">Correct answers earn <?= (int) $s['points_correct'] ?> points, wrong answers lose <?= (int) $s['points_wrong'] ?>, and a timeout scores 0.</p>
    </section>

    <aside class="panel board-panel">
        <h2>Leaderboard</h2>
        <?php if ($lb): ?>
        <ol class="board">
            <?php foreach ($lb as $row): ?>
            <li class="<?= $row['username'] === $user['username'] ? 'me' : '' ?>">
                <span class="name"><?= e($row['username']) ?></span>
                <span class="pts"><?= (int) $row['best'] ?></span>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php else: ?>
        <p class="empty">No scores yet. Finish a game to take the first spot.</p>
        <?php endif; ?>
        <?php if ($last): ?>
        <p class="last">Your last game: <strong><?= (int) $last['total_points'] ?></strong> points
            (<?= (int) $last['correct_answers'] ?> right, <?= (int) $last['wrong_answers'] ?> wrong)</p>
        <?php endif; ?>
    </aside>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
