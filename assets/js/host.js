(() => {
    'use strict';

    const base = document.body.dataset.base || '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const room = document.querySelector('.room');
    const pin = room.dataset.pin;
    const $ = (id) => document.getElementById(id);
    let sync;
    let state = null;

    // Countdown bar: display only. The server owns the clock and decides when time is up.
    const timer = KBCTimer.create($('timebar'), {
        onExpire: () => {
            if (state && state.phase === 'open' && state.current_question) {
                KBCAudio.loop('timer', false);   // time is up: ticking stops, buzzer sounds
                KBCAudio.play('timeout');
                state = { ...state, phase: 'closed', question_open: false, remaining_time: 0 };
                render(state);   // swaps "Close submissions" for the Reveal button
            }
        },
    });
    function applyTimer(d) {
        const q = state?.current_question;
        if (!state || state.status !== 'active' || !q) { timer.hide(); KBCAudio.loop('timer', false); return; }
        if (state.phase === 'open') {
            const rem = d.remaining_time ?? null;   // idle polls omit it when no clock is running
            if (rem === null) timer.hide();         // memory game while the items are on screen
            else timer.run(rem, state.timer_seconds, `${q.id}:${q.reveal_stage}`);
            KBCAudio.loop('timer', rem !== null && rem > 0);   // ticking while the clock runs
        } else if (state.phase === 'closed') {
            timer.freeze();
            KBCAudio.loop('timer', false);
        } else {
            timer.hide();
            KBCAudio.loop('timer', false);
        }
    }
    let shownQid = null;
    let listLoaded = false;
    let celebrated = false;   // confetti fires once per finished game

    async function post(url, data) {
        const ctrl = new AbortController();
        const kill = setTimeout(() => ctrl.abort(), 10000);   // never wait forever on a hung request
        try {
            const res = await fetch(base + url, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(data),
                signal: ctrl.signal,
            });
            return await res.json();
        } catch {
            return { ok: false, error: 'Could not reach the server. Check your connection.' };
        } finally {
            clearTimeout(kill);
        }
    }

    /**
     * Run a host action with its button disabled, and ALWAYS re-enable it afterwards (success, failure
     * or timeout). The button is captured by the caller: event.currentTarget is null once an await has
     * passed. Only one action runs at a time, so a double tap can't skip a question.
     */
    let busy = false;
    async function withBusy(btn, fn) {
        if (busy) return;
        busy = true;
        btn.disabled = true;
        try {
            await fn();
        } finally {
            busy = false;
            btn.disabled = false;
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
                if (s.current_question && s.phase !== 'revealed') li.classList.add(p.answered ? 'did-answer' : 'waiting');
            } else {
                li.textContent = p.name;
            }
            return li;
        }));
    }

    // ----- Standings (between questions) and podium (game over) -----
    function renderRankList(ol, players) {
        ol.replaceChildren(...players.map((p) => {
            const li = document.createElement('li');
            li.className = 'rank-row' + (p.rank <= 3 ? ` top top-${p.rank}` : '');
            li.append(Object.assign(document.createElement('span'), { className: 'rank-n', textContent: String(p.rank) }),
                      Object.assign(document.createElement('span'), { className: 'name', textContent: p.name }),
                      Object.assign(document.createElement('span'), { className: 'pts', textContent: String(p.score) }));
            return li;
        }));
    }

    const MEDAL = { 1: 'gold', 2: 'silver' };
    function renderPodium(s) {
        const top = s.players.slice(0, 3);
        const winners = s.players.filter((p) => p.rank === 1);
        $('podium-title').textContent = !s.players.length ? 'Final standings'
            : winners.length > 1 ? `It’s a tie between ${winners.map((w) => w.name).join(' and ')}!`
            : `${winners[0].name} wins!`;

        $('podium').replaceChildren(...top.map((p, i) => {
            const col = document.createElement('div');
            col.className = `podium-col place-${i + 1} medal-${MEDAL[p.rank] || 'bronze'}`;
            const who = document.createElement('div');
            who.className = 'podium-who';
            who.append(Object.assign(document.createElement('span'), { className: 'podium-name', textContent: p.name }),
                       Object.assign(document.createElement('span'), { className: 'podium-pts', textContent: `${p.score} pts` }));
            const bar = document.createElement('div');
            bar.className = 'podium-bar';
            bar.append(Object.assign(document.createElement('span'), { className: 'medal', textContent: String(p.rank) }));
            col.append(who, bar);
            return col;
        }));
        renderRankList($('podium-rest'), s.players.slice(3));
        $('podium-rest').hidden = s.players.length <= 3;

        if (!celebrated && s.players.length) {
            celebrated = true;
            setTimeout(() => KBCConfetti.burst({ count: 160 }), 2300);   // lands as the winner's bar finishes rising
        }
    }

    // ----- Question console -----
    const fmtAnswer = (q, a) => (q.options && q.options[a] ? `${a}: ${q.options[a]}` : a);

    let lastControlsQid;   // undefined until the first render
    function renderQuestion(s) {
        const q = s.current_question;
        const qid = q ? q.id : null;
        if (qid !== lastControlsQid) {
            lastControlsQid = qid;
            busy = false;   // a new question always starts with the controls ready
            ['close-btn', 'reveal-btn', 'next-btn', 'board-btn'].forEach((id) => { $(id).disabled = false; });
        }
        $('q-control').hidden = false;

        // A question goes open -> closed (time up / everyone answered / host closed) -> revealed.
        // Only the host's Reveal button moves it to the last step.
        const open = s.phase === 'open';
        const closed = s.phase === 'closed';
        const revealed = s.phase === 'revealed';
        const timeUp = s.remaining_time === 0;

        $('close-btn').hidden = !open;                 // gone once time is up or everyone has answered
        $('reveal-btn').hidden = !closed;
        $('next-btn').hidden = !!q && !revealed;       // next question only after the reveal
        $('board-btn').hidden = !q || !revealed;
        $('next-btn').textContent = q ? 'Next question' : 'Ask a question';

        if (!q) {
            $('hq-type').textContent = 'Standings';
            $('hq-state').textContent = 'Everyone is looking at the leaderboard';
            $('hq-text').textContent = '';
            $('hq-options').replaceChildren();
            KBCMedia.clear($('hq-media'));
            KBCMedia.renderItems($('hq-items'), null);
            $('stage-c').hidden = true;
            $('stage-f').hidden = true;
            document.querySelector('.answer-peek').hidden = true;
            document.querySelector('.progress-wrap').hidden = true;
            return;
        }
        document.querySelector('.answer-peek').hidden = false;
        document.querySelector('.progress-wrap').hidden = false;

        $('hq-type').textContent = `Type ${q.type}`;
        $('hq-state').textContent = open ? 'Accepting answers'
            : closed ? `${timeUp ? 'Time’s up' : 'Submissions closed'}. Players are waiting for you to reveal the answer`
            : 'Answer revealed';
        $('hq-text').textContent = q.text;
        KBCMedia.render($('hq-media'), q, { autoplay: false, open });   // host audio: controls only, so rooms don't double-play

        renderStageControls(s, q, open);

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
        if (revealed) {   // once revealed it is public anyway
            val.classList.remove('is-hidden');
            $('peek-btn').textContent = 'Hide answer';
            $('peek-btn').setAttribute('aria-pressed', 'true');
        }

        const total = s.total_players || 0;
        const done = s.players_answered || 0;
        $('progress-fill').style.width = total ? `${Math.round((done / total) * 100)}%` : '0%';
        $('progress').setAttribute('aria-valuemax', String(total));
        $('progress').setAttribute('aria-valuenow', String(done));
        $('progress-text').textContent = closed && total && done === total
            ? `Everyone has answered (${done} of ${total}).`
            : `${done} of ${total} answered`;
    }

    // Type C: reveal buttons (forward only). Type F: Hide & Ask. Shown only while the question is open.
    function renderStageControls(s, q, open) {
        const stage = q.reveal_stage || 0;
        const isC = q.type === 'C' && open;
        const isF = q.type === 'F' && open;
        $('stage-c').hidden = !isC;
        $('stage-f').hidden = !isF;

        document.querySelectorAll('#stage-c [data-stage]').forEach((b) => {
            const target = Number(b.dataset.stage);
            b.disabled = target <= stage;
            b.classList.toggle('is-current', target === stage);
        });
        const asking = stage >= 1;
        $('hide-ask-btn').hidden = asking;
        $('stage-f-label').textContent = asking ? 'Players are typing from memory' : 'Players are memorising the items';

        // Items stay on the host screen only for phase 1 (the server stops sending them after Hide & Ask).
        KBCMedia.renderItems($('hq-items'), q.type === 'F' && open ? q.items : null);
    }

    async function loadQuestionList() {
        if (listLoaded) return;
        listLoaded = true;
        const res = await post('api/host_controls.php', { action: 'list_questions', pin });
        if (!res.ok) { listLoaded = false; return; }
        $('pick-select').replaceChildren(...res.questions.map((q) =>
            Object.assign(document.createElement('option'), { value: String(q.id), textContent: `#${q.id} [${q.type}] ${q.text}` })));
    }

    // Sounds that mark a change from one state to the next (needs the previous state, so not in render()).
    let audioQid;        // undefined until the first state: a reload mid-question stays quiet
    let prevPhase = null;
    function audioEvents(s) {
        const qid = s.current_question?.id ?? null;
        if (audioQid !== undefined && qid !== null && qid !== audioQid && s.status === 'active' && s.phase === 'open') {
            KBCAudio.play('present');   // a new question is on screen
        }
        if (prevPhase && prevPhase !== 'revealed' && s.phase === 'revealed' && qid === audioQid) {
            KBCAudio.loop('timer', false);
            KBCAudio.play(s.players_correct > 0 ? 'correct' : 'wrong');   // someone got it right: cheer; nobody did: groan
        }
        audioQid = qid;
        prevPhase = s.phase;
    }

    function render(s) {
        state = s;
        KBCAudio.loop('welcome', s.status === 'lobby');   // lobby music only before the game starts
        renderPlayers(s);

        const standings = s.status === 'active' && !s.current_question;
        const over = s.status === 'finished';
        $('host-board').hidden = !standings;
        $('host-podium').hidden = !over;
        $('pin-card').hidden = over;
        $('players-card').hidden = standings || over;
        if (standings) renderRankList($('rank-list'), s.players);
        if (over) renderPodium(s); else celebrated = false;

        const start = $('start-btn');
        if (s.status === 'lobby') {
            $('room-status').textContent = s.player_count
                ? `${s.player_count} player${s.player_count === 1 ? '' : 's'} ready`
                : 'Waiting for players to join';
            start.hidden = false;
            start.disabled = s.player_count < 1;
            $('q-control').hidden = true;
            KBCMedia.clear($('hq-media'));
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
    $('start-btn').addEventListener('click', (ev) => {
        const btn = ev.currentTarget;
        withBusy(btn, () => control('start_game'))
            .finally(() => { btn.disabled = !state || state.status !== 'lobby' || state.player_count < 1; });
    });
    $('close-btn').addEventListener('click', (ev) => {
        const btn = ev.currentTarget;
        withBusy(btn, () => control('close_question'));
    });
    $('reveal-btn').addEventListener('click', (ev) => {
        const btn = ev.currentTarget;
        withBusy(btn, () => control('reveal_answer'));
    });
    $('next-btn').addEventListener('click', (ev) => {
        const btn = ev.currentTarget;
        withBusy(btn, () => control('next_question'));
    });
    $('board-btn').addEventListener('click', (ev) => {
        const btn = ev.currentTarget;
        withBusy(btn, () => control('show_leaderboard'));
    });
    $('pick-btn').addEventListener('click', async () => {
        const id = Number($('pick-select').value);
        if (!id) return;
        const res = await control('next_question', { question_id: id });
        if (res.ok) $('pick').open = false;
    });
    document.querySelectorAll('#stage-c [data-stage], #hide-ask-btn').forEach((b) => {
        b.addEventListener('click', () => withBusy(b, () => control('update_reveal_stage', { stage: Number(b.dataset.stage) })));
    });
    $('peek-btn').addEventListener('click', () => {
        const hidden = $('hq-answer').classList.toggle('is-hidden');
        $('peek-btn').textContent = hidden ? 'Show answer' : 'Hide answer';
        $('peek-btn').setAttribute('aria-pressed', String(!hidden));
    });
    $('new-session-btn').addEventListener('click', async (ev) => {
        const btn = ev.currentTarget;
        const err = $('podium-error');
        err.hidden = true;
        btn.disabled = true;
        const res = await post('api/host_controls.php', { action: 'reset_room', pin });
        btn.disabled = false;
        if (!res.ok) { err.textContent = res.error || 'Could not start a new session.'; err.hidden = false; return; }
        sync.refresh();
    });
    $('end-btn').addEventListener('click', () => {
        if (window.confirm('End the game for everyone?')) control('end_game');
    });

    sync = KBCSync.start({
        pin,
        interval: Number(room.dataset.interval) || 1500,
        onState: (s) => { audioEvents(s); render(s); },
        onPoll: applyTimer,
        onConnection: (ok) => { $('conn-banner').hidden = ok; },
        onFatal: () => { window.location.href = base + 'index.php'; },
    });
})();
