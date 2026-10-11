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

    <section class="panel wait-card" id="screen-question" hidden>
        <h1>Get ready</h1>
        <p class="lead">The first question is coming up.</p>
    </section>

    <section class="panel wait-card" id="screen-over" hidden>
        <h1>Game over</h1>
        <p class="lead">Thanks for playing.</p>
        <a class="btn btn-gold" href="index.php">Back to start</a>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
