(() => {
    'use strict';

    const base = document.body.dataset.base || '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const $ = (id) => document.getElementById(id);
    const LEVELS = { 1: 'Easy', 2: 'Medium', 3: 'Hard' };

    async function api(url, method = 'GET', body = null) {
        const opts = { method, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } };
        if (body) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        try {
            const res = await fetch(base + url, opts);
            return await res.json();
        } catch {
            return { ok: false, error: 'Could not reach the server. Check your connection.' };
        }
    }

    function el(tag, props = {}, ...kids) {
        const n = document.createElement(tag);
        Object.assign(n, props);
        kids.forEach((k) => n.append(k));
        return n;
    }

    // ---------- Tabs ----------
    const tabs = [['atab-questions', 'pane-questions'], ['atab-settings', 'pane-settings']];
    tabs.forEach(([tabId]) => $(tabId).addEventListener('click', () => {
        tabs.forEach(([t, p]) => {
            const on = t === tabId;
            $(t).classList.toggle('is-active', on);
            $(t).setAttribute('aria-selected', on);
            $(p).hidden = !on;
        });
    }));

    // ---------- Question table ----------
    const state = { page: 1, pages: 1, q: '', difficulty: 0, rows: [] };

    async function loadQuestions() {
        const params = new URLSearchParams({ page: state.page, q: state.q, difficulty: state.difficulty });
        const res = await api('api/admin_questions.php?' + params);
        const body = $('q-body');
        body.replaceChildren();
        if (!res.ok) {
            $('q-empty').textContent = res.error || 'Could not load questions.';
            $('q-empty').hidden = false;
            return;
        }
        state.rows = res.questions;
        state.page = res.page;
        state.pages = res.pages;
        $('q-empty').hidden = res.questions.length > 0;
        $('q-empty').textContent = 'No questions match. Add one or clear the search.';

        res.questions.forEach((q) => {
            const correct = q['opt_' + q.correct_opt.toLowerCase()];
            const actions = el('td', { className: 'row-actions' });
            const edit = el('button', { type: 'button', className: 'btn btn-ghost btn-sm', textContent: 'Edit' });
            const del = el('button', { type: 'button', className: 'btn btn-ghost btn-sm', textContent: 'Delete' });
            edit.addEventListener('click', () => openEditor(q));
            del.addEventListener('click', () => openDelete(q));
            actions.append(edit, ' ', del);

            body.append(el('tr', {},
                el('td', { className: 'qid', textContent: q.id }),
                el('td', { className: 'qtext' }, document.createTextNode(q.question_text), el('small', { textContent: 'Answer: ' + correct })),
                el('td', {}, el('span', { className: 'ans', textContent: q.correct_opt })),
                el('td', {}, el('span', { className: 'lvl lvl-' + q.difficulty, textContent: LEVELS[q.difficulty] || q.difficulty })),
                actions,
            ));
        });

        $('q-pageinfo').textContent = `Page ${state.page} of ${state.pages} (${res.total} questions)`;
        $('q-prev').disabled = state.page <= 1;
        $('q-next').disabled = state.page >= state.pages;
    }

    let searchTimer;
    $('q-search').addEventListener('input', (ev) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { state.q = ev.target.value.trim(); state.page = 1; loadQuestions(); }, 250);
    });
    $('q-diff').addEventListener('change', (ev) => { state.difficulty = Number(ev.target.value); state.page = 1; loadQuestions(); });
    $('q-prev').addEventListener('click', () => { state.page--; loadQuestions(); });
    $('q-next').addEventListener('click', () => { state.page++; loadQuestions(); });

    // ---------- Add / edit modal ----------
    const qDialog = $('q-dialog');
    const qForm = $('q-form');
    const qError = $('q-error');

    function openEditor(q = null) {
        qForm.reset();
        qError.hidden = true;
        $('q-dialog-title').textContent = q ? 'Edit question' : 'Add question';
        qForm.elements.id.value = q ? q.id : '';
        if (q) {
            ['question_text', 'opt_a', 'opt_b', 'opt_c', 'opt_d', 'correct_opt', 'difficulty']
                .forEach((k) => { qForm.elements[k].value = q[k]; });
        }
        qDialog.showModal();
        qForm.elements.question_text.focus();
    }
    $('q-add').addEventListener('click', () => openEditor());
    $('q-cancel').addEventListener('click', () => qDialog.close());

    qForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        qError.hidden = true;
        if (!qForm.checkValidity()) { qForm.reportValidity(); return; }

        const data = Object.fromEntries(new FormData(qForm));
        const id = data.id;
        delete data.id;
        data.action = id ? 'update' : 'create';
        if (id) data.id = Number(id);

        const btn = $('q-save');
        btn.disabled = true;
        const res = await api('api/admin_questions.php', 'POST', data);
        btn.disabled = false;

        if (!res.ok) { qError.textContent = res.error || 'Could not save the question.'; qError.hidden = false; return; }
        qDialog.close();
        if (!id) { state.page = 1; state.q = ''; state.difficulty = 0; $('q-search').value = ''; $('q-diff').value = '0'; }
        loadQuestions();
    });

    // ---------- Delete modal ----------
    const delDialog = $('del-dialog');
    let pending = null;
    function openDelete(q) {
        pending = q;
        $('del-error').hidden = true;
        const t = q.question_text;
        $('del-text').textContent = `"${t.length > 90 ? t.slice(0, 90) + '…' : t}" will be removed permanently.`;
        delDialog.showModal();
    }
    $('del-cancel').addEventListener('click', () => delDialog.close());
    $('del-confirm').addEventListener('click', async () => {
        if (!pending) return;
        const res = await api('api/admin_questions.php', 'POST', { action: 'delete', id: pending.id });
        if (!res.ok) { $('del-error').textContent = res.error || 'Could not delete the question.'; $('del-error').hidden = false; return; }
        delDialog.close();
        if (state.rows.length === 1 && state.page > 1) state.page--;
        loadQuestions();
    });

    // Click on backdrop closes a dialog.
    [qDialog, delDialog].forEach((d) => d.addEventListener('click', (ev) => { if (ev.target === d) d.close(); }));

    // ---------- Global settings ----------
    const gs = $('gs-form');
    gs.querySelectorAll('.lifeline').forEach((row) => {
        const toggle = row.querySelector('input[type="checkbox"]');
        const uses = row.querySelector('.uses input');
        const sync = () => { uses.disabled = !toggle.checked; };
        toggle.addEventListener('change', sync);
        sync();
    });
    gs.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const msg = $('gs-msg');
        if (!gs.checkValidity()) { gs.reportValidity(); return; }
        const data = {};
        gs.querySelectorAll('input').forEach((i) => { data[i.name] = i.type === 'checkbox' ? i.checked : i.value; });
        const res = await api('api/admin_settings.php', 'POST', data);
        msg.textContent = res.ok ? 'Global settings saved.' : (res.error || 'Could not save settings.');
        msg.classList.toggle('is-error', !res.ok);
        msg.hidden = false;
    });

    loadQuestions();
})();
