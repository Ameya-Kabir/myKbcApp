<?php
require_once __DIR__ . '/../includes/admin_check.php';
require_once __DIR__ . '/../config/settings.php';

$user = admin_gate(false);
$base = '../';
$s = get_settings(null);

$lifelines = ['5050' => '50:50', 'poll' => 'Audience poll', 'expert' => 'Ask the expert'];

$pageTitle   = 'KBC Quiz – Admin';
$bodyClass   = 'page-admin';
$extraCss    = ['assets/css/admin.css'];
$extraScripts = ['assets/js/admin.js'];
require __DIR__ . '/../includes/header.php';
?>
<main class="admin">
    <h1>Admin</h1>
    <div class="admin-tabs" role="tablist">
        <button role="tab" class="atab is-active" id="atab-questions" aria-selected="true" aria-controls="pane-questions">Question bank</button>
        <button role="tab" class="atab" id="atab-settings" aria-selected="false" aria-controls="pane-settings">Global settings</button>
    </div>

    <section id="pane-questions" class="pane" role="tabpanel" aria-labelledby="atab-questions">
        <div class="toolbar">
            <input type="search" id="q-search" placeholder="Search question text" aria-label="Search questions">
            <select id="q-diff" aria-label="Filter by difficulty">
                <option value="0">All difficulties</option>
                <option value="1">Easy</option>
                <option value="2">Medium</option>
                <option value="3">Hard</option>
            </select>
            <button type="button" class="btn btn-gold btn-sm" id="q-add">Add question</button>
        </div>
        <div class="table-wrap">
            <table class="qtable">
                <thead><tr><th>#</th><th>Question</th><th>Answer</th><th>Level</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody id="q-body"></tbody>
            </table>
        </div>
        <p class="empty" id="q-empty" hidden>No questions match. Add one or clear the search.</p>
        <div class="pager">
            <button type="button" class="btn btn-ghost btn-sm" id="q-prev">Previous</button>
            <span id="q-pageinfo"></span>
            <button type="button" class="btn btn-ghost btn-sm" id="q-next">Next</button>
        </div>
    </section>

    <section id="pane-settings" class="pane" role="tabpanel" aria-labelledby="atab-settings" hidden>
        <p class="lead">These are the defaults every player starts with. Players can override questions, timer and lifelines on their own dashboard.</p>
        <form id="gs-form" class="gs-form" novalidate>
            <div class="grid-4">
                <label>Points for a correct answer
                    <input type="number" name="points_correct" min="1" max="1000" value="<?= (int) $s['points_correct'] ?>" required>
                </label>
                <label>Points lost for a wrong answer
                    <input type="number" name="points_wrong" min="0" max="1000" value="<?= (int) $s['points_wrong'] ?>" required>
                </label>
                <label>Seconds per question
                    <input type="number" name="timer_seconds" min="5" max="120" value="<?= (int) $s['timer_seconds'] ?>" required>
                </label>
                <label>Questions per game
                    <input type="number" name="num_questions" min="1" max="50" value="<?= (int) $s['num_questions'] ?>" required>
                </label>
            </div>
            <fieldset>
                <legend>Lifelines</legend>
                <?php foreach ($lifelines as $k => $name): ?>
                <div class="lifeline">
                    <label class="switch">
                        <input type="checkbox" name="lifeline_<?= $k ?>" <?= $s["lifeline_$k"] === '1' ? 'checked' : '' ?>>
                        <span class="track" aria-hidden="true"></span>
                        <span class="lf-text"><strong><?= e($name) ?></strong><small>Enabled by default</small></span>
                    </label>
                    <label class="uses">Uses
                        <input type="number" name="limit_<?= $k ?>" min="1" max="5" value="<?= (int) $s["limit_$k"] ?>">
                    </label>
                </div>
                <?php endforeach; ?>
            </fieldset>
            <p class="form-msg" id="gs-msg" role="status" hidden></p>
            <button type="submit" class="btn btn-gold">Save settings</button>
        </form>
    </section>
</main>

<dialog id="q-dialog" class="modal" aria-labelledby="q-dialog-title">
    <form id="q-form" method="dialog" novalidate>
        <h2 id="q-dialog-title">Add question</h2>
        <input type="hidden" name="id">
        <label>Question
            <textarea name="question_text" rows="3" maxlength="1000" required></textarea>
        </label>
        <div class="opt-grid">
            <?php foreach (['A', 'B', 'C', 'D'] as $L): ?>
            <label>Option <?= $L ?>
                <input type="text" name="opt_<?= strtolower($L) ?>" maxlength="255" required>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="row-2">
            <label>Correct answer
                <select name="correct_opt" required>
                    <option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option>
                </select>
            </label>
            <label>Difficulty
                <select name="difficulty" required>
                    <option value="1">Easy</option><option value="2">Medium</option><option value="3">Hard</option>
                </select>
            </label>
        </div>
        <p class="form-error" id="q-error" role="alert" hidden></p>
        <div class="actions">
            <button type="button" class="btn btn-ghost" id="q-cancel">Cancel</button>
            <button type="submit" class="btn btn-gold" id="q-save">Save question</button>
        </div>
    </form>
</dialog>

<dialog id="del-dialog" class="modal modal-sm" aria-labelledby="del-title">
    <h2 id="del-title">Delete this question?</h2>
    <p id="del-text" class="lead"></p>
    <p class="form-error" id="del-error" role="alert" hidden></p>
    <div class="actions">
        <button type="button" class="btn btn-ghost" id="del-cancel">Keep it</button>
        <button type="button" class="btn btn-danger" id="del-confirm">Delete question</button>
    </div>
</dialog>
<?php require __DIR__ . '/../includes/footer.php'; ?>
