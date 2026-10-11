(() => {
    'use strict';

    const base = document.body.dataset.base || '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const room = document.querySelector('.room');
    const pin = room.dataset.pin;
    const $ = (id) => document.getElementById(id);
    const screens = ['screen-wait', 'screen-question', 'screen-board', 'screen-over'];
    const REVEAL_DELAY_MS = 1000;   // orange "locked" beat before green / red

    let last = null;          // latest full state from the server
    let typed = '';           // text answer being typed, kept across re-renders
    let busy = false;         // a submit request is in flight

    const show = (id) => {
        screens.forEach((s) => { $(s).hidden = s !== id; });
        if (id !== 'screen-question') KBCMedia.clear($('media'));   // stop audio when leaving a question
    };

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

    const myScore = (s) => s.players.find((p) => p.name === s.me)?.score ?? 0;
    const signed = (n) => (n > 0 ? `+${n}` : String(n));

    function chips(list, players) {
        list.replaceChildren(...players.map((p) => Object.assign(document.createElement('li'), { textContent: p.name })));
    }

    // ----- Rendering: the server state decides everything -----
    function render(s) {
        last = s;
        if (s.status === 'lobby') {
            show('screen-wait');
            chips($('player-list'), s.players);
            $('wait-sub').textContent = `${s.player_count} player${s.player_count === 1 ? '' : 's'} in the room.`;
        } else if (s.status === 'finished') {
            renderOver(s);
        } else if (!s.current_question) {
            renderBoard(s);
        } else {
            renderQuestion(s);
        }
    }

    function renderQuestion(s) {
        show('screen-question');
        const q = s.current_question;
        const mine = s.my_answer || { submitted: false };
        const open = s.question_open;

        $('my-score').textContent = String(myScore(s));
        $('q-meta').textContent = open ? 'Question open' : 'Answers closed';
        $('q-text').textContent = q.text;
        KBCMedia.render($('media'), q, { autoplay: open });   // rebuilt only when the question changes

        const list = $('options');
        const form = $('text-form');
        list.replaceChildren();
        $('reveal-line').hidden = true;
        form.hidden = true;

        const locked = mine.submitted || !open;
        const correct = s.reveal?.correct_answer;

        if (q.input === 'choice') {
            Object.entries(q.options).forEach(([letter, text]) => {
                const li = document.createElement('li');
                li.className = 'hex-wrap';
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'hex opt';
                b.dataset.letter = letter;
                b.disabled = locked;
                b.append(Object.assign(document.createElement('span'), { className: 'opt-letter', textContent: letter + ':' }),
                         Object.assign(document.createElement('span'), { className: 'opt-text', textContent: text }));
                if (mine.submitted && mine.answer.toUpperCase() === letter) b.classList.add('is-locked');
                if (!open && correct) {
                    if (letter === correct) b.classList.add('is-correct');
                    else if (mine.submitted && mine.answer.toUpperCase() === letter) b.classList.replace('is-locked', 'is-wrong');
                }
                if (!locked) b.addEventListener('click', () => submit(letter));
                li.append(b);
                list.append(li);
            });
        } else if (!open || mine.submitted) {
            // Text question, locked or revealed: show what they typed instead of the input.
            if (mine.submitted) {
                const v = document.createElement('li');
                v.className = 'typed-answer';
                v.textContent = `Your answer: ${mine.answer}`;
                list.append(v);
            }
        } else {
            form.hidden = false;
            const input = $('text-answer');
            input.value = typed;
            input.disabled = false;
            $('text-submit').disabled = false;
        }

        if (!open && correct && q.input === 'text') {
            $('reveal-line').textContent = `Correct answer: ${correct}`;
            $('reveal-line').hidden = false;
        }
        if (!open && correct && q.input === 'choice') {
            $('reveal-line').textContent = `Correct answer: ${correct}: ${q.options[correct]}`;
            $('reveal-line').hidden = false;
        }

        // Status line
        const status = $('q-status');
        status.classList.remove('is-bad');
        if (open && mine.submitted) {
            status.textContent = `Locked in. Waiting for others (${s.players_answered}/${s.total_players} answered)…`;
        } else if (!open && !s.reveal) {
            status.textContent = 'Checking your answer…';   // the beat between orange and green / red
        } else if (open) {
            status.textContent = q.input === 'choice' ? 'Choose your answer.' : 'Type your answer and lock it in.';
        } else if (!mine.submitted) {
            status.textContent = 'No answer. 0 points.';
            status.classList.add('is-bad');
        } else if (mine.correct) {
            status.textContent = `Correct! ${signed(mine.points)} points`;
        } else {
            status.textContent = `Not quite. ${signed(mine.points)} points`;
            status.classList.add('is-bad');
        }
    }

    function fillBoard(list, s) {
        list.replaceChildren(...s.players.slice(0, 10).map((p) => {
            const li = document.createElement('li');
            if (p.name === s.me) li.className = 'me';
            li.append(Object.assign(document.createElement('span'), { className: 'name', textContent: p.name }),
                      Object.assign(document.createElement('span'), { className: 'pts', textContent: String(p.score) }));
            return li;
        }));
    }
    const rankOf = (s) => s.players.findIndex((p) => p.name === s.me) + 1;

    function renderBoard(s) {
        show('screen-board');
        const rank = rankOf(s);
        $('board-line').textContent = rank ? `You are #${rank} of ${s.player_count} with ${myScore(s)} points.` : '';
        fillBoard($('board-list'), s);
    }

    function renderOver(s) {
        show('screen-over');
        const rank = rankOf(s);
        $('final-line').textContent = rank
            ? `You finished #${rank} of ${s.player_count} with ${myScore(s)} points.`
            : 'Thanks for playing.';
        fillBoard($('final-board'), s);
    }

    // ----- Submitting -----
    async function submit(answer) {
        if (busy || !last?.question_open) return;
        busy = true;
        document.querySelectorAll('#options .opt, #text-submit, #text-answer').forEach((b) => { b.disabled = true; });
        $('q-status').textContent = 'Locking in…';
        const res = await post('api/submit_answer.php', { pin, answer });
        busy = false;

        if (res.ok || res.code === 'already') {
            // Optimistic lock until the next poll confirms it from the server.
            render({ ...last, my_answer: { submitted: true, answer }, players_answered: last.players_answered + 1 });
            sync.refresh();
            return;
        }
        if (res.code === 'closed') { sync.refresh(); return; }
        // Failed (validation, network): let them try again.
        render(last);
        $('q-status').textContent = res.error || 'Could not lock in your answer.';
        $('q-status').classList.add('is-bad');
    }

    $('text-answer').addEventListener('input', (e) => { typed = e.target.value; });
    $('text-form').addEventListener('submit', (e) => {
        e.preventDefault();
        const v = $('text-answer').value.trim();
        if (!v) { $('text-answer').focus(); return; }
        submit(v);
    });

    // A new question starts with an empty text box.
    let lastQid = null;
    let prev = null;
    let revealTimer = 0;
    const sync = KBCSync.start({
        pin,
        interval: Number(room.dataset.interval) || 1500,
        onState: (s) => {
            const qid = s.current_question?.id ?? null;
            if (qid !== lastQid) { typed = ''; lastQid = qid; }

            // Host just closed the question I answered: flash my choice orange, then reveal.
            const closing = prev && prev.question_open && !s.question_open && prev.status === 'active'
                && qid !== null && qid === prev.current_question?.id && s.my_answer?.submitted;
            prev = s;
            clearTimeout(revealTimer);
            if (closing) {
                const { reveal, ...beforeReveal } = s;
                render(beforeReveal);   // chosen option stays orange; no verdict yet
                revealTimer = setTimeout(() => render(s), REVEAL_DELAY_MS);
            } else {
                render(s);
            }
        },
        onConnection: (ok) => { $('conn-banner').hidden = ok; },
        onFatal: () => { window.location.href = base + 'index.php'; },
    });
})();
