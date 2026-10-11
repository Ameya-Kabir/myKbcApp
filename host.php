<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/room_lib.php';

$pin  = (isset($_GET['pin']) && is_string($_GET['pin'])) ? $_GET['pin'] : '';
$room = valid_pin($pin) ? find_room($pin) : null;
if ($room === null || (int) $room['host_id'] !== $user['id']) {
    header('Location: index.php');
    exit;
}

$pageTitle    = 'KBC Party – Host';
$bodyClass    = 'page-host';
$extraScripts = ['assets/js/sync.js', 'assets/js/timer.js', 'assets/js/audio.js', 'assets/js/media.js', 'assets/js/confetti.js', 'assets/js/host.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="room" data-pin="<?= e($pin) ?>" data-interval="<?= KBC_POLL_INTERVAL_MS ?>">
    <p id="conn-banner" class="conn-banner" role="status" hidden>Connection lost. Trying to reconnect…</p>

    <section class="panel pin-card" id="pin-card">
        <p class="pin-label">Join at <strong><?= e($_SERVER['HTTP_HOST'] ?? 'this site') ?></strong> with room PIN</p>
        <div class="pin-display" aria-label="Room PIN <?= e(implode(' ', str_split($pin))) ?>"><?= e(format_pin($pin)) ?></div>
        <p class="status-line" id="room-status" role="status">Waiting for players to join</p>
        <p class="form-error" id="host-error" role="alert" hidden></p>
        <div class="actions">
            <a class="btn btn-ghost" href="index.php">Leave room</a>
            <button type="button" class="btn btn-gold" id="start-btn" disabled>Start game</button>
        </div>
    </section>

    <section class="panel standings" id="host-board" hidden>
        <h1>Leaderboard</h1>
        <ol class="rank-list" id="rank-list"></ol>
    </section>

    <section class="panel podium-panel" id="host-podium" hidden>
        <h1 id="podium-title">Final standings</h1>
        <div class="podium" id="podium"></div>
        <ol class="rank-list rest" id="podium-rest"></ol>
        <div class="actions">
            <a class="btn btn-ghost btn-xl" href="index.php">Leave room</a>
            <button type="button" class="btn btn-gold btn-xl" id="new-session-btn">Start new session</button>
        </div>
        <p class="form-error" id="podium-error" role="alert" hidden></p>
    </section>

    <section class="panel q-control" id="q-control" hidden>
        <div class="qc-head">
            <span class="badge" id="hq-type"></span>
            <span id="hq-state" class="hq-state"></span>
        </div>
        <div class="timebar" id="timebar" role="timer" aria-label="Time left" hidden>
            <div class="timebar-track"><span class="timebar-fill"></span></div>
            <b class="timebar-num">0</b>
        </div>
        <p class="hq-text" id="hq-text"></p>
        <div class="media-wrap" id="hq-media" hidden></div>
        <div class="memory-wrap" id="hq-items" hidden></div>
        <ul class="hq-options" id="hq-options"></ul>

        <div class="stage-controls" id="stage-c" hidden>
            <span class="peek-label">Picture reveal</span>
            <button type="button" class="btn btn-ghost btn-xl" data-stage="1">Reveal 25%</button>
            <button type="button" class="btn btn-ghost btn-xl" data-stage="2">Reveal 50%</button>
            <button type="button" class="btn btn-ghost btn-xl" data-stage="3">Reveal 100%</button>
        </div>
        <div class="stage-controls" id="stage-f" hidden>
            <span class="peek-label" id="stage-f-label">Memory round</span>
            <button type="button" class="btn btn-gold btn-xl" id="hide-ask-btn" data-stage="1">Hide &amp; Ask</button>
        </div>

        <div class="answer-peek">
            <span class="peek-label">Correct answer</span>
            <span class="peek-value is-hidden" id="hq-answer"></span>
            <button type="button" class="icon-btn" id="peek-btn" aria-pressed="false">Show answer</button>
        </div>

        <div class="progress-wrap">
            <div class="progress" role="progressbar" id="progress" aria-valuemin="0" aria-valuemax="1" aria-valuenow="0" aria-label="Players answered">
                <span id="progress-fill"></span>
            </div>
            <p id="progress-text" class="progress-text">0 of 0 answered</p>
        </div>

        <div class="big-actions">
            <button type="button" class="btn btn-reveal" id="reveal-btn" hidden>Reveal answer</button>
            <button type="button" class="btn btn-gold btn-xl" id="close-btn">Close submissions</button>
            <button type="button" class="btn btn-gold btn-xl" id="next-btn">Next question</button>
            <button type="button" class="btn btn-ghost btn-xl" id="board-btn">Show leaderboard</button>
        </div>

        <details class="pick" id="pick">
            <summary>Choose a specific question</summary>
            <div class="pick-row">
                <select id="pick-select" aria-label="Question to ask next"></select>
                <button type="button" class="btn btn-ghost" id="pick-btn">Ask this one</button>
            </div>
        </details>
        <p class="form-error" id="ctrl-error" role="alert" hidden></p>
        <div class="end-row"><button type="button" class="link" id="end-btn">End game</button></div>
    </section>

    <section class="panel players-card" id="players-card">
        <h2>Players <span class="count" id="player-count">0</span></h2>
        <ul class="player-list" id="player-list"></ul>
        <p class="empty" id="no-players">Nobody has joined yet. Share the PIN.</p>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
