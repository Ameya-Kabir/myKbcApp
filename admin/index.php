<?php
require_once __DIR__ . '/../includes/admin_check.php';
require_once __DIR__ . '/../includes/room_lib.php';

$user = admin_gate(false);
$base = '../';

$pageTitle    = 'KBC Party – Admin';
$bodyClass    = 'page-admin';
$extraCss     = ['assets/css/admin.css'];
$extraScripts = ['assets/js/admin.js'];
require __DIR__ . '/../includes/header.php';
?>
<main class="admin">
    <h1>Question bank</h1>
    <section class="pane" aria-label="Questions">
        <div class="toolbar">
            <input type="search" id="q-search" placeholder="Search question text" aria-label="Search questions">
            <select id="q-type-filter" aria-label="Filter by type">
                <option value="">All types</option>
                <option value="A1">A1 · Multiple choice</option>
                <option value="A2">A2 · Typed answer</option>
                <option value="B">B · Picture</option>
                <option value="C">C · Progressive reveal</option>
                <option value="D">D · Music</option>
                <option value="E">E · Picture + music</option>
                <option value="F">F · Memory</option>
            </select>
            <button type="button" class="btn btn-gold btn-sm" id="q-add">Add question</button>
        </div>
        <div class="table-wrap">
            <table class="qtable">
                <thead><tr><th>#</th><th>Type</th><th>Question</th><th>Answer</th><th><span class="sr-only">Actions</span></th></tr></thead>
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
</main>

<dialog id="q-dialog" class="modal" aria-labelledby="q-dialog-title">
    <form id="q-form" method="dialog" novalidate>
        <h2 id="q-dialog-title">Add question</h2>
        <input type="hidden" name="id">

        <div class="row-2">
            <label>Question type
                <select name="q_type" id="f-type">
                    <option value="A1">A1 · Multiple choice</option>
                    <option value="A2">A2 · Typed answer</option>
                    <option value="B">B · Picture</option>
                    <option value="C">C · Progressive reveal picture</option>
                    <option value="D">D · Music round</option>
                    <option value="E">E · Picture + music</option>
                    <option value="F">F · Memory game</option>
                </select>
            </label>
            <label data-field="mode">Answer format
                <select name="answer_mode" id="f-mode">
                    <option value="mcq">Multiple choice</option>
                    <option value="text">Typed answer</option>
                </select>
            </label>
        </div>

        <label>Question
            <textarea name="question_text" rows="3" maxlength="1000" required></textarea>
        </label>

        <label data-field="media"><span id="media-label">Image URL</span>
            <input type="text" name="media_url" maxlength="500" placeholder="https://… or assets/media/photo.jpg">
            <small>A web address, or a file you uploaded under assets/</small>
        </label>
        <label data-field="audio">Audio URL
            <input type="text" name="audio_url" maxlength="500" placeholder="https://… or assets/media/clip.mp3">
        </label>

        <div class="opt-grid" data-field="mcq">
            <?php foreach (['A', 'B', 'C', 'D'] as $L): ?>
            <label>Option <?= $L ?>
                <input type="text" name="opt_<?= strtolower($L) ?>" maxlength="255">
            </label>
            <?php endforeach; ?>
        </div>
        <label data-field="mcq">Correct option
            <select name="correct_choice">
                <option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option>
            </select>
        </label>

        <label data-field="items">Items to memorise (one per line)
            <textarea name="items" rows="4" placeholder="apple&#10;candle&#10;river"></textarea>
        </label>
        <label data-field="text"><span id="correct-label">Correct answer</span>
            <input type="text" name="correct_text" maxlength="255">
            <small id="correct-help"></small>
        </label>
        <label class="check" data-field="case">
            <input type="checkbox" name="case_sensitive"> Match capital letters exactly (e.g. strict CamelCase)
        </label>

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
