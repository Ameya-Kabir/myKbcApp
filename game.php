<?php
require_once __DIR__ . '/includes/auth_check.php';

$pageTitle    = 'KBC Quiz – Hot seat';
$bodyClass    = 'page-game';
$extraScripts = ['assets/js/game.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="game">
    <section id="intro" class="panel intro">
        <h1>Take the hot seat</h1>
        <p id="intro-text" role="status">Loading your questions…</p>
        <div class="actions">
            <a class="btn btn-ghost" href="dashboard.php">Back to lobby</a>
            <button type="button" class="btn btn-gold" id="begin-btn" disabled>Start</button>
        </div>
    </section>

    <div id="play" hidden>
        <div class="hud">
            <div class="hud-item"><span class="hud-label">Score</span><strong id="score">0</strong></div>
            <div class="hud-item hud-mid"><span id="qcount">Question 1 of 1</span></div>
            <div class="hud-item hud-tools">
                <button type="button" class="icon-btn" id="mute-btn" aria-pressed="false">Sound on</button>
                <button type="button" class="icon-btn" id="quit-btn">Quit</button>
            </div>
        </div>

        <div class="lifelines" id="lifelines" aria-label="Lifelines"></div>

        <div class="timer" id="timer" role="timer" aria-label="Time left">
            <svg viewBox="0 0 100 100" aria-hidden="true">
                <circle class="ring-bg" cx="50" cy="50" r="45"/>
                <circle class="ring" id="ring" cx="50" cy="50" r="45"/>
            </svg>
            <span id="timer-num">30</span>
        </div>

        <div class="hex-wrap q-wrap"><div class="hex q-box"><p id="q-text"></p></div></div>

        <ul class="options" id="options">
            <?php foreach (['A', 'B', 'C', 'D'] as $L): ?>
            <li class="hex-wrap">
                <button type="button" class="hex opt" data-letter="<?= $L ?>" aria-keyshortcuts="<?= $L ?>">
                    <span class="opt-letter"><?= $L ?>:</span><span class="opt-text"></span>
                </button>
            </li>
            <?php endforeach; ?>
        </ul>

        <p id="status" class="status" role="status" aria-live="polite"></p>
    </div>

    <section id="summary" class="panel summary" hidden></section>
</main>

<dialog id="ll-dialog" class="modal modal-dark" aria-labelledby="ll-title">
    <h2 id="ll-title"></h2>
    <div id="ll-body"></div>
    <p class="hint">The clock keeps running while this is open.</p>
    <div class="actions"><button type="button" class="btn btn-gold" id="ll-close">Back to question</button></div>
</dialog>

<dialog id="quit-dialog" class="modal modal-dark modal-sm" aria-labelledby="quit-title">
    <h2 id="quit-title">End the game now?</h2>
    <p class="lead">Your answers so far are scored and saved. Unanswered questions count for nothing.</p>
    <div class="actions">
        <button type="button" class="btn btn-ghost" id="quit-cancel">Keep playing</button>
        <button type="button" class="btn btn-gold" id="quit-confirm">End game</button>
    </div>
</dialog>

<audio id="snd-bg" src="assets/audio/background.mp3" loop preload="auto"></audio>
<audio id="snd-lock" src="assets/audio/lock.mp3" preload="auto"></audio>
<audio id="snd-correct" src="assets/audio/correct.mp3" preload="auto"></audio>
<audio id="snd-wrong" src="assets/audio/wrong.mp3" preload="auto"></audio>
<audio id="snd-tick" src="assets/audio/timer.mp3" preload="auto"></audio>
<?php require __DIR__ . '/includes/footer.php'; ?>
