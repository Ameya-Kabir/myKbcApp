(() => {
    'use strict';

    const base = document.body.dataset.base || '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const $ = (id) => document.getElementById(id);
    const TYPE_NAMES = { A1: 'Multiple choice', A2: 'Typed answer', B: 'Picture', C: 'Progressive reveal', D: 'Music', E: 'Picture + music', F: 'Memory' };

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

    // ---------- Question table ----------
    const state = { page: 1, pages: 1, q: '', type: '', rows: [] };

    function answerText(q) {
        if (q.answer_mode === 'mcq') return `${q.correct_answer}: ${q['opt_' + q.correct_answer.toLowerCase()]}`;
        return q.correct_answer;
    }

    async function loadQuestions() {
        const params = new URLSearchParams({ page: state.page, q: state.q, type: state.type });
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
            const actions = el('td', { className: 'row-actions' });
            const edit = el('button', { type: 'button', className: 'btn btn-ghost btn-sm', textContent: 'Edit' });
            const del = el('button', { type: 'button', className: 'btn btn-ghost btn-sm', textContent: 'Delete' });
            edit.addEventListener('click', () => openEditor(q));
            del.addEventListener('click', () => openDelete(q));
            actions.append(edit, ' ', del);

            const ans = answerText(q);
            body.append(el('tr', {},
                el('td', { className: 'qid', textContent: q.id }),
                el('td', {}, el('span', { className: 'type-chip', title: TYPE_NAMES[q.q_type], textContent: q.q_type })),
                el('td', { className: 'qtext', textContent: q.question_text }),
                el('td', { className: 'qans', textContent: ans.length > 70 ? ans.slice(0, 70) + '…' : ans }),
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
    $('q-type-filter').addEventListener('change', (ev) => { state.type = ev.target.value; state.page = 1; loadQuestions(); });
    $('q-prev').addEventListener('click', () => { state.page--; loadQuestions(); });
    $('q-next').addEventListener('click', () => { state.page++; loadQuestions(); });

    // ---------- Dynamic editor form ----------
    const qDialog = $('q-dialog');
    const qForm = $('q-form');
    const qError = $('q-error');
    const f = qForm.elements;
    const group = (name) => [...qForm.querySelectorAll(`[data-field="${name}"]`)];

    function effectiveMode(type) {
        if (type === 'A1') return 'mcq';
        if (type === 'A2' || type === 'F') return 'text';
        return f.answer_mode.value;
    }

    /** Show only the fields the chosen type needs; hidden inputs are disabled so they skip validation. */
    function syncForm() {
        const t = f.q_type.value;
        const mode = effectiveMode(t);
        const visible = {
            mode: ['B', 'C', 'D', 'E'].includes(t),
            media: ['B', 'C', 'D', 'E', 'F'].includes(t),
            audio: t === 'E',
            mcq: mode === 'mcq',
            items: t === 'F',
            text: mode === 'text',
            case: mode === 'text',
        };
        Object.entries(visible).forEach(([name, on]) => group(name).forEach((node) => {
            node.hidden = !on;
            node.querySelectorAll('input, select, textarea').forEach((i) => { i.disabled = !on; });
        }));

        f.media_url.required = ['B', 'C', 'D', 'E'].includes(t);
        f.audio_url.required = t === 'E';
        ['opt_a', 'opt_b', 'opt_c', 'opt_d'].forEach((n) => { f[n].required = mode === 'mcq'; });
        f.items.required = t === 'F';
        f.correct_text.required = mode === 'text' && t !== 'F';

        $('media-label').textContent = t === 'D' ? 'Audio URL' : (t === 'F' ? 'Image URL (optional)' : 'Image URL');
        $('correct-label').textContent = t === 'F' ? 'Answer players must type (optional)' : 'Correct answer';
        $('correct-help').textContent = t === 'F'
            ? 'Leave blank to expect the items above, in order, separated by commas.'
            : 'Typed answers ignore extra spaces' + (f.case_sensitive.checked ? '.' : ' and capital letters.');
    }
    f.q_type.addEventListener('change', syncForm);
    f.answer_mode.addEventListener('change', syncForm);
    f.case_sensitive.addEventListener('change', syncForm);

    function openEditor(q = null) {
        qForm.reset();
        qError.hidden = true;
        $('q-dialog-title').textContent = q ? 'Edit question' : 'Add question';
        f.id.value = q ? q.id : '';
        if (q) {
            f.q_type.value = q.q_type;
            f.answer_mode.value = q.answer_mode;
            ['question_text', 'media_url', 'audio_url', 'opt_a', 'opt_b', 'opt_c', 'opt_d', 'items'].forEach((k) => { f[k].value = q[k]; });
            if (q.answer_mode === 'mcq') f.correct_choice.value = q.correct_answer; else f.correct_text.value = q.correct_answer;
            f.case_sensitive.checked = q.case_sensitive === 1;
        }
        syncForm();
        qDialog.showModal();
        f.q_type.focus();
    }
    $('q-add').addEventListener('click', () => openEditor());
    $('q-cancel').addEventListener('click', () => qDialog.close());

    qForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        qError.hidden = true;
        if (!qForm.checkValidity()) { qForm.reportValidity(); return; }

        const t = f.q_type.value;
        const mode = effectiveMode(t);
        const data = {
            action: f.id.value ? 'update' : 'create',
            q_type: t,
            answer_mode: mode,
            question_text: f.question_text.value,
            media_url: f.media_url.disabled ? '' : f.media_url.value,
            audio_url: f.audio_url.disabled ? '' : f.audio_url.value,
            items: f.items.disabled ? '' : f.items.value,
            correct_answer: mode === 'mcq' ? f.correct_choice.value : f.correct_text.value,
            case_sensitive: mode === 'text' && f.case_sensitive.checked,
        };
        if (mode === 'mcq') ['opt_a', 'opt_b', 'opt_c', 'opt_d'].forEach((k) => { data[k] = f[k].value; });
        if (f.id.value) data.id = Number(f.id.value);

        const btn = $('q-save');
        btn.disabled = true;
        const res = await api('api/admin_questions.php', 'POST', data);
        btn.disabled = false;

        if (!res.ok) { qError.textContent = res.error || 'Could not save the question.'; qError.hidden = false; return; }
        qDialog.close();
        if (!f.id.value) { state.page = 1; state.q = ''; state.type = ''; $('q-search').value = ''; $('q-type-filter').value = ''; }
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

    [qDialog, delDialog].forEach((d) => d.addEventListener('click', (ev) => { if (ev.target === d) d.close(); }));

    loadQuestions();
})();
