(() => {
    'use strict';

    const base = document.body.dataset.base || '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const room = document.querySelector('.room');
    const pin = room.dataset.pin;
    const $ = (id) => document.getElementById(id);
    const screens = ['screen-wait', 'screen-question', 'screen-board', 'screen-over'];
    const REVEAL_DELAY_MS = 1000;   // orange "locked" beat before green / red

    // Countdown bar. The server owns the clock; this just shows it and flags when it hits zero.
    const timer = KBCTimer.create($('timebar'), {
        onExpire: () => {
            if (last && last.phase === 'open' && last.current_question) {
                last = { ...last, phase: 'closed', question_open: false, remaining_time: 0 };
                render(last);   // lock inputs now; the answer stays hidden until the host reveals it
            }
        },
    });

    function applyTimer(d) {
        const q = last?.current_question;
        if (!last || last.status !== 'active' || !q) { timer.hide(); return; }
        if (last.phase === 'open') {
            const rem = d.remaining_time ?? null;   // idle polls omit it when no clock is running
            if (rem === null) timer.hide();         // e.g. memory game while the items are on screen
            else timer.run(rem, last.timer_seconds, `${q.id}:${q.reveal_stage}`);
        } else if (last.phase === 'closed') {
            timer.freeze();
        } else {
            timer.hide();
        }
    }

    let last = null;          // latest full state from the server
    let typed = '';           // text answer being typed, kept across re-renders
    let busy = false;         // a submit request is in flight

    const show = (id) => {
        screens.forEach((s) => { $(s).hidden = s !== id; });
        if (id !== 'screen-question') {   // stop audio / drop memory cards and the answer box when leaving a question
            KBCMedia.clear($('media'));
            KBCMedia.renderItems($('items'), null);
            $('text-form')?.remove();
            timer.hide();
        }
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
        if (s.status !== 'finished') celebrated = false;
        if (s.status === 'lobby') {
            show('screen-wait');
            $('wait-title').textContent = 'Waiting for the host to start…';
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
        const open = s.phase === 'open';
        const revealed = s.phase === 'revealed';
        const timeUp = s.remaining_time === 0;

        $('my-score').textContent = String(myScore(s));
        $('q-meta').textContent = open ? 'Question open' : (revealed ? 'Answer revealed' : (timeUp ? 'Time’s up' : 'Answers closed'));
        $('q-text').textContent = q.text;
        KBCMedia.render($('media'), q, { autoplay: open, open });   // rebuilt only when the question changes

        const list = $('options');
        list.replaceChildren();
        $('reveal-line').hidden = true;

        // Memory game phase 1: show the items, and keep the answer box out of the DOM entirely.
        const memorising = q.type === 'F' && open && (q.reveal_stage || 0) < 1;
        KBCMedia.renderItems($('items'), memorising ? q.items : null);
        const wantForm = q.input === 'text' && open && !mine.submitted && !memorising;
        mountTextForm(wantForm);

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
                if (revealed && correct) {
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
        }

        if (revealed && correct && q.input === 'text') {
            $('reveal-line').textContent = `Correct answer: ${correct}`;
            $('reveal-line').hidden = false;
        }
        if (revealed && correct && q.input === 'choice') {
            $('reveal-line').textContent = `Correct answer: ${correct}: ${q.options[correct]}`;
            $('reveal-line').hidden = false;
        }

        // Status line
        const status = $('q-status');
        status.classList.remove('is-bad');
        if (open && mine.submitted) {
            status.textContent = `Locked in. Waiting for others (${s.players_answered}/${s.total_players} answered)…`;
        } else if (revealed && !s.reveal) {
            status.textContent = 'Checking your answer…';   // the beat between orange and green / red
        } else if (memorising) {
            status.textContent = 'Memorise the items, in order. The host will hide them soon.';
        } else if (open) {
            status.textContent = q.type === 'C' ? 'The picture is being revealed. You get one guess.'
                : q.type === 'F' ? 'Type the items back in order, separated by commas.'
                : q.input === 'choice' ? 'Choose your answer.' : 'Type your answer and lock it in.';
        } else if (!revealed) {
            // Submissions are closed but the host has not revealed yet: no verdict, no colours.
            status.textContent = mine.submitted
                ? 'Locked in. Waiting for the host to reveal the answer…'
                : `${timeUp ? 'Time’s up!' : 'Answers are closed.'} Waiting for Host to reveal…`;
            if (!mine.submitted) status.classList.add('is-bad');
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

    const ordinal = (n) => {
        const v = n % 100;
        if (v >= 11 && v <= 13) return `${n}th`;
        return `${n}${({ 1: 'st', 2: 'nd', 3: 'rd' })[n % 10] || 'th'}`;
    };

    // Between questions the host shows the standings on the main screen; phones just point there.
    function renderBoard(s) {
        show('screen-board');
        const me = s.players.find((p) => p.name === s.me);
        $('board-line').textContent = me ? `You are ${ordinal(me.rank)} of ${s.player_count} with ${me.score} points.` : '';
    }

    let celebrated = false;
    function renderOver(s) {
        show('screen-over');
        const sum = s.summary;                      // placing and stats are computed on the server
        const card = $('screen-over');
        const won = !!sum && sum.rank === 1;
        card.classList.toggle('winner', won);

        if (!sum) {
            $('over-placing').textContent = 'Game over';
            $('over-score').textContent = '';
            $('over-breakdown').textContent = 'Thanks for playing.';
        } else {
            $('over-placing').textContent = won
                ? (sum.tied ? 'You tied for the win!' : 'You won!')
                : `You ${sum.tied ? 'tied for' : 'placed'} ${ordinal(sum.rank)}!`;
            $('over-score').textContent = String(sum.score);
            const parts = [`${sum.correct} correct`, `${sum.wrong} wrong`];
            if (sum.no_answer) parts.push(`${sum.no_answer} unanswered`);
            $('over-breakdown').textContent = `${parts.join(', ')} out of ${sum.questions} question${sum.questions === 1 ? '' : 's'}`;
        }

        $('final-board').replaceChildren(...s.players.slice(0, 5).map((p) => {
            const li = document.createElement('li');
            if (p.name === s.me) li.className = 'me';
            li.append(Object.assign(document.createElement('span'), { className: 'name', textContent: p.name }),
                      Object.assign(document.createElement('span'), { className: 'pts', textContent: String(p.score) }));
            return li;
        }));

        if (won && !celebrated) { celebrated = true; KBCConfetti.burst({ count: 110 }); }
    }

    // ----- Submitting -----
    async function submit(answer) {
        if (busy || last?.phase !== 'open') return;
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
        if (res.code === 'closed' || res.code === 'late') {
            // Too late (or the host closed it): lock the screen, keep the answer hidden, let the poll confirm.
            last = { ...last, phase: 'closed', question_open: false, remaining_time: 0 };
            render(last);
            sync.refresh();
            return;
        }
        // Failed (validation, network): let them try again.
        render(last);
        $('q-status').textContent = res.error || 'Could not lock in your answer.';
        $('q-status').classList.add('is-bad');
    }

    // The answer box is created only when it is allowed (and removed otherwise), not just hidden.
    function mountTextForm(on) {
        let form = $('text-form');
        if (!on) { form?.remove(); return; }
        if (form) return;   // already there: keep focus and what the player has typed
        form = $('tpl-text-form').content.firstElementChild.cloneNode(true);
        $('answer-area').append(form);
        const input = form.querySelector('input');
        input.value = typed;
        input.addEventListener('input', (e) => { typed = e.target.value; });
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const v = input.value.trim();
            if (!v) { input.focus(); return; }
            submit(v);
        });
    }

    // A new question starts with an empty text box.
    let lastQid = null;
    let prev = null;
    let revealTimer = 0;
    const sync = KBCSync.start({
        pin,
        interval: Number(room.dataset.interval) || 1500,
        onPoll: applyTimer,
        onState: (s) => {
            const qid = s.current_question?.id ?? null;
            if (qid !== lastQid) { typed = ''; lastQid = qid; }

            // Host just closed the question I answered: flash my choice orange, then reveal.
            const closing = prev && prev.phase !== 'revealed' && s.phase === 'revealed' && prev.status === 'active'
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
