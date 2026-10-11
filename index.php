<?php
require_once __DIR__ . '/includes/room_lib.php';

$user = current_user();
$prefill = (isset($_GET['pin']) && is_string($_GET['pin']) && preg_match('/^\d{6}$/', $_GET['pin'])) ? $_GET['pin'] : '';

$open = null;
if ($user !== null) {
    $stmt = db()->prepare("SELECT room_pin, status FROM sessions WHERE host_id = :u AND status <> 'finished' ORDER BY id DESC LIMIT 1");
    $stmt->execute([':u' => $user['id']]);
    $open = $stmt->fetch() ?: null;
}

$pageTitle    = 'KBC Party';
$bodyClass    = $user ? 'page-home' : 'page-login';
$extraScripts = ['assets/js/lobby.js'];
require __DIR__ . '/includes/header.php';
?>
<?php if ($user === null): ?>
<main class="stage" data-authed="0" data-prefill="<?= e($prefill) ?>">
    <section class="emblem-wrap">
        <svg class="emblem" viewBox="0 0 200 200" aria-hidden="true">
            <defs>
                <radialGradient id="core" cx="50%" cy="40%" r="70%"><stop offset="0" stop-color="#4b2aa8"/><stop offset="1" stop-color="#0c0a3a"/></radialGradient>
                <linearGradient id="gold" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffe08a"/><stop offset=".5" stop-color="#f2b632"/><stop offset="1" stop-color="#b9791a"/></linearGradient>
            </defs>
            <polygon points="100,6 181,53 181,147 100,194 19,147 19,53" fill="none" stroke="url(#gold)" stroke-width="3"/>
            <polygon points="100,20 169,60 169,140 100,180 31,140 31,60" fill="url(#core)" stroke="url(#gold)" stroke-width="1.5"/>
            <text x="100" y="116" text-anchor="middle" font-family="Marcellus, serif" font-size="46" fill="url(#gold)">KBC</text>
        </svg>
        <h1>Got a room PIN?</h1>
        <form id="join-form" class="join-form" novalidate>
            <label class="sr-only" for="pin-input">Room PIN</label>
            <input id="pin-input" class="pin-input" name="pin" inputmode="numeric" autocomplete="off" maxlength="7" placeholder="000 000" value="<?= e($prefill) ?>">
            <button type="submit" class="btn btn-gold">Join game</button>
        </form>
        <p class="form-error" id="join-error" role="alert" hidden></p>
        <p class="join-note" id="join-note" hidden></p>
    </section>

    <section class="panel auth-panel" aria-labelledby="auth-title">
        <div class="tabs" role="tablist">
            <button role="tab" id="tab-login" aria-selected="true" aria-controls="form-login" class="tab is-active">Log in</button>
            <button role="tab" id="tab-register" aria-selected="false" aria-controls="form-register" class="tab">Create account</button>
        </div>
        <h2 id="auth-title" class="sr-only">Account access</h2>

        <form id="form-login" class="auth-form" data-action="login" novalidate>
            <label>Username or email <input type="text" name="login" autocomplete="username" required></label>
            <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
            <p class="form-error" role="alert" hidden></p>
            <button type="submit" class="btn btn-gold">Log in</button>
        </form>

        <form id="form-register" class="auth-form" data-action="register" novalidate hidden>
            <label>Username <input type="text" name="username" autocomplete="username" minlength="3" maxlength="30" required><small>3–30 letters, numbers or underscores</small></label>
            <label>Email <input type="email" name="email" autocomplete="email" required></label>
            <label>Password <input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required><small>At least 8 characters</small></label>
            <p class="form-error" role="alert" hidden></p>
            <button type="submit" class="btn btn-gold">Create account</button>
        </form>
    </section>
</main>
<?php else: ?>
<main class="home" data-authed="1" data-prefill="<?= e($prefill) ?>">
    <div class="home-tabs" role="tablist" aria-label="Play a game">
        <div class="hex-wrap is-active">
            <button type="button" role="tab" class="hex home-tab is-active" id="tab-join" aria-selected="true" aria-controls="panel-join">Join</button>
        </div>
        <div class="hex-wrap">
            <button type="button" role="tab" class="hex home-tab" id="tab-host" aria-selected="false" aria-controls="panel-host" tabindex="-1">Host</button>
        </div>
    </div>

    <section class="panel home-panel" id="panel-join" role="tabpanel" aria-labelledby="tab-join">
        <h1>Join a game</h1>
        <p class="lead">Enter the PIN shown on the host’s screen.</p>
        <form id="join-form" class="join-form" novalidate>
            <label class="sr-only" for="pin-input">Room PIN</label>
            <input id="pin-input" class="pin-input" name="pin" inputmode="numeric" autocomplete="off" maxlength="7" placeholder="000 000" value="<?= e($prefill) ?>">
            <button type="submit" class="btn btn-gold">Join game</button>
        </form>
        <p class="form-error" id="join-error" role="alert" hidden></p>
    </section>

    <section class="panel home-panel" id="panel-host" role="tabpanel" aria-labelledby="tab-host" hidden>
        <h2>Host a game</h2>
        <?php if ($open): ?>
        <p class="lead">You already have a room open (PIN <strong><?= e(format_pin($open['room_pin'] ?? '')) ?></strong>). Creating a new one closes it.</p>
        <a class="btn btn-ghost" href="host.php?pin=<?= e($open['room_pin']) ?>">Return to your room</a>
        <?php endif; ?>
        <form id="host-form" novalidate>
            <div class="row-2">
                <label>Points for a correct answer
                    <input type="number" name="points_correct" min="<?= KBC_SETTING_RANGES['points_correct'][0] ?>" max="<?= KBC_SETTING_RANGES['points_correct'][1] ?>" value="<?= KBC_SESSION_DEFAULTS['points_correct'] ?>" required>
                </label>
                <label>Points lost for a wrong answer
                    <input type="number" name="points_wrong" min="<?= KBC_SETTING_RANGES['points_wrong'][0] ?>" max="<?= KBC_SETTING_RANGES['points_wrong'][1] ?>" value="<?= KBC_SESSION_DEFAULTS['points_wrong'] ?>" required>
                </label>
            </div>
            <label>Seconds per question
                <input type="number" name="timer_seconds" min="<?= KBC_SETTING_RANGES['timer_seconds'][0] ?>" max="<?= KBC_SETTING_RANGES['timer_seconds'][1] ?>" value="<?= KBC_SESSION_DEFAULTS['timer_seconds'] ?>" required>
                <small>Submissions close when the clock runs out or everyone has answered. You reveal the answer yourself.</small>
            </label>
            <p class="form-error" id="host-error" role="alert" hidden></p>
            <button type="submit" class="btn btn-gold">Create room</button>
        </form>
    </section>
</main>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
