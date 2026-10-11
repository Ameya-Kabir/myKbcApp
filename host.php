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
$extraScripts = ['assets/js/sync.js', 'assets/js/host.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="room" data-pin="<?= e($pin) ?>" data-interval="<?= KBC_POLL_INTERVAL_MS ?>">
    <p id="conn-banner" class="conn-banner" role="status" hidden>Connection lost. Trying to reconnect…</p>

    <section class="panel pin-card">
        <p class="pin-label">Join at <strong><?= e($_SERVER['HTTP_HOST'] ?? 'this site') ?></strong> with room PIN</p>
        <div class="pin-display" aria-label="Room PIN <?= e(implode(' ', str_split($pin))) ?>"><?= e(format_pin($pin)) ?></div>
        <p class="status-line" id="room-status" role="status">Waiting for players to join</p>
        <p class="form-error" id="host-error" role="alert" hidden></p>
        <div class="actions">
            <a class="btn btn-ghost" href="index.php">Leave room</a>
            <button type="button" class="btn btn-gold" id="start-btn" disabled>Start game</button>
        </div>
    </section>

    <section class="panel players-card">
        <h2>Players <span class="count" id="player-count">0</span></h2>
        <ul class="player-list" id="player-list"></ul>
        <p class="empty" id="no-players">Nobody has joined yet. Share the PIN.</p>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
