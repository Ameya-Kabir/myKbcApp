(() => {
    'use strict';

    const base = document.body.dataset.base || '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const room = document.querySelector('.room');
    const pin = room.dataset.pin;
    const $ = (id) => document.getElementById(id);
    let sync;
    let state = null;
    let shownQid = null;
    let listLoaded = false;

    async function post(url, data) {
        try {
            const res = await fetch(base + url, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(data),
            });
            return await res.json();
        } catch {
            return { ok: false, error: 'Could not reach the server. Check your connection.' };
        }
    }

    async function control(action, extra = {}) {
        const err = $('ctrl-error');
        err.hidden = true;
        const res = await post('api/host_controls.php', { action, pin, ...extra });
        if (!res.ok) { err.textContent = res.error || 'That did not work.'; err.hidden = false; return res; }
        sync.refresh();   // show the change now instead of at the next tick
        return res;
    }

    // ----- Players / leaderboard card -----
    function renderPlayers(s) {
        const active = s.status !== 'lobby';
        $('player-count').textContent = String(s.player_count);
        $('no-players').hidden = s.player_count > 0;
        $('player-list').classList.toggle('is-board', active);
        $('player-list').replaceChildren(...s.players.map((p, i) => {
            const li = document.createElement('li');
            if (active) {
                li.append(Object.assign(document.createElement('span'), { className: 'rank', textContent: String(i + 1) }),
                          Object.assign(document.createElement('span'), { className: 'name', textContent: p.name }),
                          Object.assign(document.createElement('span'), { className: 'pts', textContent: String(p.score) }));
                if (s.question_open && s.current_question) li.classList.add(p.answered ? 'did-answer' : 'waiting');
            } else {
                li.textContent = p.name;
            }
            return li;
        }));
    }

    // ----- Question console -----
    const fmtAnswer = (q, a) => (q.options && q.options[a] ? `${a}: ${q.options[a]}` : a);

    function renderQuestion(s) {
        const q = s.current_question;
        $('q-control').hidden = false;
        const open = s.question_open && !!q;

        $('close-btn').hidden = !open;
        $('next-btn').hidden = open;
        $('board-btn').hidden = open || !q;   // already on the standings when there is no question
        $('next-btn').textContent = q ? 'Next question' : 'Ask a question';

        if (!q) {
            $('hq-type').textContent = 'Standings';
            $('hq-state').textContent = 'Everyone is looking at the leaderboard';
            $('hq-text').textContent = '';
            $('hq-options').replaceChildren();
            document.querySelector('.answer-peek').hidden = true;
            document.querySelector('.progress-wrap').hidden = true;
            return;
        }
        document.querySelector('.answer-peek').hidden = false;
        document.querySelector('.progress-wrap').hidden = false;

        $('hq-type').textContent = `Type ${q.type}`;
        $('hq-state').textContent = open ? 'Accepting answers' : 'Submissions closed';
        $('hq-text').textContent = q.text;

        const correct = s.host_only?.correct_answer ?? s.reveal?.correct_answer ?? '';
        $('hq-options').replaceChildren(...(q.options ? Object.entries(q.options) : []).map(([L, text]) => {
            const li = document.createElement('li');
            li.append(Object.assign(document.createElement('b'), { textContent: L + ':' }), ' ' + text);
            return li;
        }));

        // The answer is blurred until the host clicks, so a projected screen doesn't give it away.
        const val = $('hq-answer');
        val.textContent = fmtAnswer(q, correct);
        if (shownQid !== q.id) {
            shownQid = q.id;
            val.classList.add('is-hidden');
            $('peek-btn').textContent = 'Show answer';
            $('peek-btn').setAttribute('aria-pressed', 'false');
        }
        if (!s.question_open) {   // after closing it is public anyway
            val.classList.remove('is-hidden');
            $('peek-btn').textContent = 'Hide answer';
            $('peek-btn').setAttribute('aria-pressed', 'true');
        }

        const total = s.total_players || 0;
        const done = s.players_answered || 0;
        $('progress-fill').style.width = total ? `${Math.round((done / total) * 100)}%` : '0%';
        $('progress').setAttribute('aria-valuemax', String(total));
        $('progress').setAttribute('aria-valuenow', String(done));
        $('progress-text').textContent = open && total && done === total
            ? 'Everyone has answered. Close submissions when ready.'
            : `${done} of ${total} answered`;
        $('close-btn').classList.toggle('is-ready', open && total > 0 && done === total);
    }

    async function loadQuestionList() {
        if (listLoaded) return;
        listLoaded = true;
        const res = await post('api/host_controls.php', { action: 'list_questions', pin });
        if (!res.ok) { listLoaded = false; return; }
        $('pick-select').replaceChildren(...res.questions.map((q) =>
            Object.assign(document.createElement('option'), { value: String(q.id), textContent: `#${q.id} [${q.type}] ${q.text}` })));
    }

    function render(s) {
        state = s;
        renderPlayers(s);

        const start = $('start-btn');
        if (s.status === 'lobby') {
            $('room-status').textContent = s.player_count
                ? `${s.player_count} player${s.player_count === 1 ? '' : 's'} ready`
                : 'Waiting for players to join';
            start.hidden = false;
            start.disabled = s.player_count < 1;
            $('q-control').hidden = true;
        } else if (s.status === 'active') {
            start.hidden = true;
            $('room-status').textContent = 'Game in progress';
            renderQuestion(s);
            loadQuestionList();
        } else {
            start.hidden = true;
            $('q-control').hidden = true;
            $('room-status').textContent = 'This game has finished';
        }
    }

    // ----- Wiring -----
    $('start-btn').addEventListener('click', async (ev) => {
        const btn = ev.currentTarget;
        btn.disabled = true;
        const res = await control('start_game');
        if (!res.ok) btn.disabled = !state || state.player_count < 1;
    });
    $('close-btn').addEventListener('click', async (ev) => {
        ev.currentTarget.disabled = true;
        await control('close_question');
        ev.currentTarget.disabled = false;
    });
    $('next-btn').addEventListener('click', async (ev) => {
        ev.currentTarget.disabled = true;
        await control('next_question');
        ev.currentTarget.disabled = false;
    });
    $('board-btn').addEventListener('click', () => control('show_leaderboard'));
    $('pick-btn').addEventListener('click', async () => {
        const id = Number($('pick-select').value);
        if (!id) return;
        const res = await control('next_question', { question_id: id });
        if (res.ok) $('pick').open = false;
    });
    $('peek-btn').addEventListener('click', () => {
        const hidden = $('hq-answer').classList.toggle('is-hidden');
        $('peek-btn').textContent = hidden ? 'Show answer' : 'Hide answer';
        $('peek-btn').setAttribute('aria-pressed', String(!hidden));
    });
    $('end-btn').addEventListener('click', () => {
        if (window.confirm('End the game for everyone?')) control('end_game');
    });

    sync = KBCSync.start({
        pin,
        interval: Number(room.dataset.interval) || 1500,
        onState: render,
        onConnection: (ok) => { $('conn-banner').hidden = ok; },
        onFatal: () => { window.location.href = base + 'index.php'; },
    });
})();
