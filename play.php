<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/room_lib.php';

$pin  = (isset($_GET['pin']) && is_string($_GET['pin'])) ? $_GET['pin'] : '';
$room = valid_pin($pin) ? find_room($pin) : null;
if ($room === null || !is_room_member((int) $room['id'], $user['id'])) {
    header('Location: index.php' . (valid_pin($pin) ? '?pin=' . $pin : ''));
    exit;
}

$pageTitle    = 'KBC Party – Play';
$bodyClass    = 'page-play';
$extraScripts = ['assets/js/sync.js', 'assets/js/play.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="room play" data-pin="<?= e($pin) ?>" data-interval="<?= KBC_POLL_INTERVAL_MS ?>">
    <p id="conn-banner" class="conn-banner" role="status" hidden>Connection lost. Trying to reconnect…</p>

    <section class="panel wait-card" id="screen-wait">
        <div class="pulse-ring" aria-hidden="true"></div>
        <h1 id="wait-title">Waiting for the host to start…</h1>
        <p class="lead" id="wait-sub">You’re in room <?= e(format_pin($pin)) ?>.</p>
        <ul class="player-list" id="player-list" aria-label="Players in the room"></ul>
    </section>

    <section class="game-screen" id="screen-question" hidden>
        <div class="hud">
            <div class="hud-item"><span class="hud-label">Your score</span><strong id="my-score">0</strong></div>
            <div class="hud-item hud-mid"><span id="q-meta"></span></div>
            <div class="hud-item"></div>
        </div>
        <div class="hex-wrap q-wrap"><div class="hex q-box"><p id="q-text"></p></div></div>
        <ul class="options" id="options"></ul>
        <form class="text-form" id="text-form" autocomplete="off" hidden>
            <label class="sr-only" for="text-answer">Your answer</label>
            <input type="text" id="text-answer" class="answer-input" maxlength="255" placeholder="Type your answer">
            <button type="submit" class="btn btn-gold" id="text-submit">Lock in</button>
        </form>
        <p id="q-status" class="status" role="status" aria-live="polite"></p>
        <p id="reveal-line" class="reveal-line" hidden></p>
    </section>

    <section class="panel wait-card" id="screen-board" hidden>
        <h1>Leaderboard</h1>
        <p class="lead" id="board-line"></p>
        <ol class="board" id="board-list"></ol>
        <p class="status">Next question coming up…</p>
    </section>

    <section class="panel wait-card" id="screen-over" hidden>
        <h1>Game over</h1>
        <p class="lead" id="final-line">Thanks for playing.</p>
        <ol class="board" id="final-board"></ol>
        <a class="btn btn-gold" href="index.php">Back to start</a>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
