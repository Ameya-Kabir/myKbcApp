<?php
require_once __DIR__ . '/config/session.php';
if (current_user() !== null) {
    header('Location: dashboard.php');
    exit;
}
$pageTitle = 'KBC Quiz – Log in';
$bodyClass = 'page-login';
require __DIR__ . '/includes/header.php';
?>
<main class="stage">
    <section class="emblem-wrap" aria-hidden="true">
        <svg class="emblem" viewBox="0 0 200 200">
            <defs>
                <radialGradient id="core" cx="50%" cy="40%" r="70%">
                    <stop offset="0" stop-color="#4b2aa8"/><stop offset="1" stop-color="#0c0a3a"/>
                </radialGradient>
                <linearGradient id="gold" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#ffe08a"/><stop offset=".5" stop-color="#f2b632"/><stop offset="1" stop-color="#b9791a"/>
                </linearGradient>
            </defs>
            <polygon points="100,6 181,53 181,147 100,194 19,147 19,53" fill="none" stroke="url(#gold)" stroke-width="3"/>
            <polygon points="100,20 169,60 169,140 100,180 31,140 31,60" fill="url(#core)" stroke="url(#gold)" stroke-width="1.5"/>
            <polygon points="100,38 153,69 153,131 100,162 47,131 47,69" fill="none" stroke="#f2b632" stroke-opacity=".35"/>
            <text x="100" y="116" text-anchor="middle" font-family="Marcellus, serif" font-size="46" fill="url(#gold)">KBC</text>
        </svg>
        <h1>Take the hot seat.</h1>
        <p>Answer against the clock, use your lifelines wisely, and climb the leaderboard.</p>
    </section>

    <section class="panel auth-panel" aria-labelledby="auth-title">
        <div class="tabs" role="tablist">
            <button role="tab" id="tab-login" aria-selected="true" aria-controls="form-login" class="tab is-active">Log in</button>
            <button role="tab" id="tab-register" aria-selected="false" aria-controls="form-register" class="tab">Create account</button>
        </div>
        <h2 id="auth-title" class="sr-only">Account access</h2>

        <form id="form-login" class="auth-form" data-action="login" novalidate>
            <label>Username or email
                <input type="text" name="login" autocomplete="username" required>
            </label>
            <label>Password
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <p class="form-error" role="alert" hidden></p>
            <button type="submit" class="btn btn-gold">Log in</button>
        </form>

        <form id="form-register" class="auth-form" data-action="register" novalidate hidden>
            <label>Username
                <input type="text" name="username" autocomplete="username" minlength="3" maxlength="30" required>
                <small>3–30 letters, numbers or underscores</small>
            </label>
            <label>Email
                <input type="email" name="email" autocomplete="email" required>
            </label>
            <label>Password
                <input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required>
                <small>At least 8 characters</small>
            </label>
            <p class="form-error" role="alert" hidden></p>
            <button type="submit" class="btn btn-gold">Create account</button>
        </form>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
