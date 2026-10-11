(() => {
    'use strict';

    const base = document.body.dataset.base || '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const $ = (id) => document.getElementById(id);
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const RING = 2 * Math.PI * 45;
    const LOCK_SUSPENSE_MS = 1500;
    const REVEAL_MS = 2800;
    const LIFELINE_LABELS = { '5050': '50:50', poll: 'Audience poll', expert: 'Ask the expert' };

    // Every request carries the CSRF token.
    async function api(url, body = {}) {
        try {
            const res = await fetch(base + url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(body),
            });
            return await res.json();
        } catch {
            return { ok: false, error: 'Could not reach the server. Check your connection.' };
        }
    }

    // ---------- Audio ----------
    const sfx = { bg: $('snd-bg'), lock: $('snd-lock'), correct: $('snd-correct'), wrong: $('snd-wrong'), tick: $('snd-tick') };
    let muted = false;
    try { muted = localStorage.getItem('kbc-muted') === '1'; } catch { /* storage unavailable */ }
    sfx.bg.volume = 0.35;

    function play(a) {
        if (muted) return;
        try { a.currentTime = 0; a.play().catch(() => {}); } catch { /* missing or empty file */ }
    }
    function syncMute() {
        const b = $('mute-btn');
        b.textContent = muted ? 'Sound off' : 'Sound on';
        b.setAttribute('aria-pressed', String(muted));
        if (muted) Object.values(sfx).forEach((a) => a.pause());
        else if (state.playing) play(sfx.bg);
    }

    // ---------- State ----------
    const state = { game: null, questions: [], idx: 0, score: 0, locked: true, playing: false, usedHere: new Set(), ended: false };
    let raf = 0;
    let lastSecond = null;

    function setStatus(text, bad = false) {
        const s = $('status');
        s.textContent = text;
        s.classList.toggle('is-bad', bad);
    }

    // ---------- Timer ----------
    function stopTimer() { cancelAnimationFrame(raf); }
    function startTimer(seconds) {
        const total = seconds * 1000;
        const t0 = performance.now();
        const timer = $('timer');
        timer.classList.remove('is-warn', 'is-danger', 'is-paused');
        lastSecond = null;
        const frame = (now) => {
            const left = Math.max(0, total - (now - t0));
            const secs = Math.ceil(left / 1000);
            $('ring').style.strokeDashoffset = String(RING * (1 - left / total));
            $('timer-num').textContent = String(secs);
            timer.classList.toggle('is-warn', secs <= 10 && secs > 5);
            timer.classList.toggle('is-danger', secs <= 5);
            if (secs <= 5 && secs !== lastSecond && secs > 0) play(sfx.tick);
            lastSecond = secs;
            if (left <= 0) { onTimeout(); return; }
            raf = requestAnimationFrame(frame);
        };
        raf = requestAnimationFrame(frame);
    }

    // ---------- Rendering ----------
    function opts() { return [...document.querySelectorAll('#options .opt')]; }
    function optFor(letter) { return opts().find((b) => b.dataset.letter === letter); }

    function renderLifelines() {
        const box = $('lifelines');
        box.replaceChildren();
        Object.entries(state.game.lifelines).forEach(([type, l]) => {
            if (!l.enabled) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'lifeline-btn';
            btn.dataset.type = type;
            btn.append(LIFELINE_LABELS[type] + ' ');
            const n = document.createElement('span');
            n.className = 'uses-left';
            n.textContent = String(l.remaining);
            btn.append(n);
            btn.disabled = state.locked || l.remaining < 1 || state.usedHere.has(type);
            btn.addEventListener('click', () => useLifeline(type));
            box.append(btn);
        });
    }

    function renderQuestion() {
        const q = state.questions[state.idx];
        $('qcount').textContent = `Question ${state.idx + 1} of ${state.questions.length}`;
        $('q-text').textContent = q.text;
        opts().forEach((b) => {
            b.className = 'hex opt';
            b.disabled = true;
            b.querySelector('.opt-text').textContent = q.options[b.dataset.letter];
        });
        state.usedHere = new Set();
        $('ring').style.strokeDashoffset = '0';
        $('timer-num').textContent = String(state.game.timer);
        $('timer').classList.remove('is-warn', 'is-danger', 'is-paused');
        setStatus('');
        renderLifelines();
    }

    // ---------- Game loop ----------
    async function runQuestion() {
        renderQuestion();
        const q = state.questions[state.idx];
        const res = await api('api/submit_answer.php', { action: 'start', question_id: q.id });
        if (!res.ok) { setStatus(res.error || 'Could not start the question.', true); return finish(); }
        state.locked = false;
        opts().forEach((b) => { b.disabled = false; });
        renderLifelines();
        startTimer(state.game.timer);
    }

    function lockUI() {
        state.locked = true;
        $('ll-dialog').open && $('ll-dialog').close();
        document.querySelectorAll('.lifeline-btn').forEach((b) => { b.disabled = true; });
    }

    async function choose(letter) {
        if (state.locked) return;
        lockUI();
        stopTimer();
        $('timer').classList.add('is-paused');
        opts().forEach((b) => { b.disabled = true; });
        optFor(letter).classList.add('is-locked');
        play(sfx.lock);
        setStatus('Locked in. Let’s see…');
        const res = await api('api/submit_answer.php', { action: 'answer', question_id: state.questions[state.idx].id, choice: letter });
        await sleep(LOCK_SUSPENSE_MS);
        await reveal(res, letter);
    }

    async function onTimeout() {
        if (state.locked) return;
        lockUI();
        stopTimer();
        opts().forEach((b) => { b.disabled = true; });
        setStatus('Time’s up.', true);
        const res = await api('api/submit_answer.php', { action: 'answer', question_id: state.questions[state.idx].id, choice: null });
        await reveal(res, null);
    }

    async function reveal(res, chosen) {
        if (!res.ok) { setStatus(res.error || 'Something went wrong.', true); await sleep(1800); return finish(); }
        opts().forEach((b) => b.classList.remove('is-locked'));
        optFor(res.correct_opt).classList.add('is-correct');
        if (res.result === 'correct') {
            play(sfx.correct);
            setStatus(`Correct! +${res.points_delta} points`);
        } else if (res.result === 'wrong') {
            optFor(chosen).classList.add('is-wrong');
            play(sfx.wrong);
            setStatus(`Wrong answer. ${res.points_delta} points`, true);
        } else {
            play(sfx.wrong);
            setStatus('Time’s up. 0 points.', true);
        }
        state.score = res.total_points;
        $('score').textContent = String(state.score);
        await sleep(REVEAL_MS);
        if (res.has_next) { state.idx++; runQuestion(); } else { finish(); }
    }

    // ---------- Lifelines ----------
    async function useLifeline(type) {
        if (state.locked || state.usedHere.has(type)) return;
        const q = state.questions[state.idx];
        document.querySelectorAll('.lifeline-btn').forEach((b) => { b.disabled = true; });
        const res = await api('api/use_lifeline.php', { question_id: q.id, lifeline_type: type });
        if (!res.ok) { setStatus(res.error || 'Could not use that lifeline.', true); renderLifelines(); return; }

        state.game.lifelines[type].remaining = res.remaining;
        state.usedHere.add(type);
        renderLifelines();
        setStatus('');

        if (type === '5050') {
            res.hide.forEach((L) => { const b = optFor(L); b.classList.add('is-hidden'); b.disabled = true; });
        } else if (type === 'poll') {
            showPoll(res.percentages);
        } else {
            showExpert(res.expert, res.hint);
        }
    }

    function openDialog(title, bodyNode) {
        $('ll-title').textContent = title;
        $('ll-body').replaceChildren(bodyNode);
        $('ll-dialog').showModal();
    }

    function showPoll(pcts) {
        const chart = document.createElement('div');
        chart.className = 'poll';
        const bars = [];
        ['A', 'B', 'C', 'D'].forEach((L) => {
            const col = document.createElement('div');
            col.className = 'poll-col';
            const pct = document.createElement('span');
            pct.className = 'poll-pct';
            pct.textContent = pcts[L] + '%';
            const bar = document.createElement('div');
            bar.className = 'poll-bar';
            const letter = document.createElement('span');
            letter.className = 'poll-letter';
            letter.textContent = L;
            col.append(pct, bar, letter);
            chart.append(col);
            bars.push([bar, pcts[L]]);
        });
        openDialog('Audience poll', chart);
        requestAnimationFrame(() => requestAnimationFrame(() => {
            bars.forEach(([bar, p]) => { bar.style.height = `calc(${p} * (100% - 3.2rem) / 100)`; });
        }));
    }

    function showExpert(name, hint) {
        const wrap = document.createElement('div');
        const quote = document.createElement('p');
        quote.className = 'expert-quote';
        quote.textContent = `“${hint}”`;
        const who = document.createElement('p');
        who.className = 'expert-name';
        who.textContent = name + ', your expert';
        wrap.append(quote, who);
        openDialog('Ask the expert', wrap);
    }

    // ---------- Game over ----------
    async function finish() {
        if (state.ended) return;
        state.ended = true;
        state.locked = true;
        stopTimer();
        $('ll-dialog').open && $('ll-dialog').close();
        state.playing = false;
        sfx.bg.pause();

        const res = await api('api/submit_score.php');
        $('play').hidden = true;
        const box = $('summary');
        box.replaceChildren();
        box.hidden = false;

        const h = document.createElement('h1');
        const actions = document.createElement('div');
        actions.className = 'actions';
        const lobby = Object.assign(document.createElement('a'), { className: 'btn btn-ghost', href: 'dashboard.php', textContent: 'Back to lobby' });
        const again = Object.assign(document.createElement('a'), { className: 'btn btn-gold', href: 'game.php', textContent: 'Play again' });
        actions.append(lobby, again);

        if (!res.ok) {
            h.textContent = 'Game ended';
            const p = Object.assign(document.createElement('p'), { className: 'lead', textContent: res.error || 'Your score could not be saved.' });
            box.append(h, p, actions);
            return;
        }

        h.textContent = 'Game over';
        const big = Object.assign(document.createElement('div'), { className: 'big-score', textContent: String(res.total_points) });
        const tally = document.createElement('div');
        tally.className = 'tally';
        [['Correct', res.correct], ['Wrong', res.wrong], ['Timed out', res.timeouts], ['Skipped', res.unanswered]].forEach(([label, n]) => {
            const s = document.createElement('span');
            const b = document.createElement('strong');
            b.textContent = String(n);
            s.append(b, ' ' + label.toLowerCase());
            tally.append(s);
        });

        const list = document.createElement('ul');
        list.className = 'review';
        res.review.forEach((r) => {
            const li = document.createElement('li');
            li.className = 'r-' + r.result;
            const q = Object.assign(document.createElement('div'), { className: 'rq', textContent: r.text });
            const a = document.createElement('div');
            a.className = 'ra';
            const correctText = `${r.correct_opt}: ${r.options[r.correct_opt]}`;
            a.append('Answer: ');
            a.append(Object.assign(document.createElement('b'), { textContent: correctText }));
            if (r.result === 'wrong') a.append(` · You chose ${r.choice}: ${r.options[r.choice]}`);
            else if (r.result === 'timeout') a.append(' · Time ran out');
            else if (r.result === 'skipped') a.append(' · Not answered');
            a.append(` · ${r.points > 0 ? '+' : ''}${r.points} pts`);
            li.append(q, a);
            list.append(li);
        });
        box.append(h, big, tally, list, actions);
        h.focus?.();
    }

    // ---------- Wiring ----------
    opts().forEach((b) => b.addEventListener('click', () => choose(b.dataset.letter)));
    document.addEventListener('keydown', (ev) => {
        if (ev.ctrlKey || ev.metaKey || ev.altKey || state.locked || document.querySelector('dialog[open]')) return;
        const L = ev.key.toUpperCase();
        if (['A', 'B', 'C', 'D'].includes(L)) {
            const b = optFor(L);
            if (b && !b.disabled) choose(L);
        }
    });
    $('ll-close').addEventListener('click', () => $('ll-dialog').close());
    $('mute-btn').addEventListener('click', () => {
        muted = !muted;
        try { localStorage.setItem('kbc-muted', muted ? '1' : '0'); } catch { /* ignore */ }
        syncMute();
    });
    $('quit-btn').addEventListener('click', () => $('quit-dialog').showModal());
    $('quit-cancel').addEventListener('click', () => $('quit-dialog').close());
    $('quit-confirm').addEventListener('click', () => { $('quit-dialog').close(); finish(); });

    async function init() {
        const intro = $('intro-text');
        const res = await api('api/fetch_questions.php');
        if (!res.ok) { intro.textContent = res.error || 'Could not load the game.'; return; }

        state.game = res.game;
        state.questions = res.questions;
        const g = res.game;
        intro.textContent = `${g.total} question${g.total === 1 ? '' : 's'}, ${g.timer} seconds each. ` +
            `Correct answers score ${g.points_correct}, wrong answers lose ${g.points_wrong}, and a timeout scores 0.` +
            (g.total < g.requested ? ` Only ${g.total} of ${g.requested} requested questions are available.` : '');

        const begin = $('begin-btn');
        begin.textContent = 'Start game';
        begin.disabled = false;
        begin.focus();
        begin.addEventListener('click', () => {
            $('intro').hidden = true;
            $('play').hidden = false;
            state.playing = true;
            syncMute();
            play(sfx.bg);
            runQuestion();
        }, { once: true });
    }

    syncMute();
    init();
})();
