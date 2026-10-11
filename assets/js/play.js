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
                remNow = 0;
                KBCAudio.hold('timer', false);   // the ticking stops just before the buzzer
                if (!last.my_answer?.submitted) KBCAudio.play('timeout');   // out of time with no answer: buzzer, then suspense
                last = { ...last, phase: 'closed', question_open: false, remaining_time: 0 };
                render(last);   // lock inputs now; the answer stays hidden until the host reveals it
            }
        },
    });

    let remNow = null;   // seconds left on the clock, as last reported by the server

    function applyTimer(d) {
        remNow = d.remaining_time ?? null;   // idle polls omit it when no clock is running
        const q = last?.current_question;
        if (!last || last.status !== 'active' || !q) { timer.hide(); }
        else if (last.phase === 'open') {
            if (remNow === null) timer.hide();   // e.g. memory game while the items are on screen
            else timer.run(remNow, last.timer_seconds, `${q.id}:${q.reveal_stage}`);
        } else if (last.phase === 'closed') {
            timer.freeze();
        } else {
            timer.hide();
        }
        syncAudio();
    }

    /**
     * Which looping / background sounds should be on right now. Called after every state change, so it
     * is the single place that decides; one-off sounds (present, lock, timeout, verdict) are played where they happen.
     *   lobby                                  -> welcome music
     *   clock running, I have not answered     -> ticking
     *   I locked in, or the clock has stopped  -> suspense, until it ends or the answer is revealed
     */
    function syncAudio() {
        const s = last;
        const inQuestion = !!s && s.status === 'active' && !!s.current_question;
        const submitted = busy || !!s?.my_answer?.submitted;   // 'busy' = my answer is on its way
        const open = inQuestion && s.phase === 'open';
        KBCAudio.hold('welcome', !!s && s.status === 'lobby');
        KBCAudio.hold('timer', open && !submitted && remNow !== null && remNow > 0);
        KBCAudio.hold('suspense', inQuestion && ((open && submitted) || s.phase === 'closed'));
    }

    let last = null;          // latest full state from the server
    let typed = '';           // text answer being typed, kept across re-renders
    let typedItems = [];      // same, per box, for the memory game
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
        if ('remaining_time' in s) remNow = s.remaining_time ?? null;
        syncAudio();
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
        mountTextForm(wantForm, q);

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
        KBCAudio.play('lock');           // lock-in sound first, so the suspense below queues up behind it
        syncAudio();                     // 'busy' is set: ticking stops at once, suspense follows the lock sound
        document.querySelectorAll('#options .opt, #text-submit, #text-answer, .memory-input').forEach((b) => { b.disabled = true; });
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
    // A memory-game question gets one box per item; every other typed question gets a single box.
    function mountTextForm(on, q) {
        let form = $('text-form');
        const count = on && q.type === 'F' ? (q.item_count || 0) : 0;
        const key = on ? `${q.id}:${count}` : '';
        if (form && (!on || form.dataset.key !== key)) { form.remove(); form = null; }   // gone, or left over from another question
        if (!on || form) return;   // already there: keep focus and what the player has typed

        form = count > 1 ? buildMemoryForm(count) : buildSingleForm();
        form.dataset.key = key;
        $('answer-area').append(form);
        if (count > 1) form.querySelector('input').focus({ preventScroll: true });
    }

    function buildSingleForm() {
        const form = $('tpl-text-form').content.firstElementChild.cloneNode(true);
        const input = form.querySelector('input');
        input.value = typed;
        input.addEventListener('input', (e) => { typed = e.target.value; });
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const v = input.value.trim();
            if (!v) { input.focus(); return; }
            submit(v);
        });
        return form;
    }

    /**
     * Memory game answer: n boxes. A comma or Tab (or Enter) moves to the next box, Backspace on an
     * empty box goes back, and pasting "red, blue, green" spreads across the boxes. The boxes are
     * joined with commas on submit, which is the format the server grades.
     */
    function buildMemoryForm(n) {
        const form = Object.assign(document.createElement('form'), { id: 'text-form', className: 'text-form multi', autocomplete: 'off' });
        const grid = Object.assign(document.createElement('div'), { className: 'memory-inputs' });
        const inputs = Array.from({ length: n }, (_, i) => {
            const el = Object.assign(document.createElement('input'), {
                type: 'text', className: 'answer-input memory-input', maxLength: 60, placeholder: String(i + 1),
                autocomplete: 'off', autocapitalize: 'none', spellcheck: false,
            });
            el.setAttribute('aria-label', `Item ${i + 1} of ${n}`);
            el.value = typedItems[i] || '';
            return el;
        });
        const save = () => { typedItems = inputs.map((x) => x.value); };
        const focusAt = (i) => { const t = inputs[Math.max(0, Math.min(n - 1, i))]; t.focus(); t.select(); };

        inputs.forEach((el, i) => {
            el.addEventListener('input', () => {
                if (el.value.includes(',')) {   // a pasted list (or a comma that slipped in): spread it out
                    const parts = el.value.split(',').map((x) => x.trim());
                    el.value = parts[0];
                    let last = i;
                    for (let k = 1; k < parts.length && i + k < n; k++) {
                        if (parts[k] !== '') inputs[i + k].value = parts[k];
                        last = i + k;
                    }
                    focusAt(last);
                }
                save();
            });
            el.addEventListener('keydown', (ev) => {
                if (ev.key === ',') { ev.preventDefault(); if (i < n - 1) focusAt(i + 1); }
                else if (ev.key === 'Enter' && i < n - 1) { ev.preventDefault(); focusAt(i + 1); }   // last box: Enter submits
                else if (ev.key === 'Backspace' && el.value === '' && i > 0) { ev.preventDefault(); focusAt(i - 1); }
                else if (ev.key === 'ArrowLeft' && el.selectionStart === 0 && el.selectionEnd === 0 && i > 0) { ev.preventDefault(); focusAt(i - 1); }
                else if (ev.key === 'ArrowRight' && el.selectionStart === el.value.length && i < n - 1) { ev.preventDefault(); focusAt(i + 1); }
            });
            grid.append(el);
        });

        const btn = Object.assign(document.createElement('button'), { type: 'submit', className: 'btn btn-gold btn-xl', id: 'text-submit', textContent: 'Submit' });
        form.append(grid, btn);
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const vals = inputs.map((x) => x.value.trim());
            if (!vals.some(Boolean)) { inputs[0].focus(); return; }
            submit(vals.join(','));
        });
        return form;
    }

    // A new question starts with an empty text box.
    let lastQid = null;
    let prev = null;
    let audioQid;   // undefined until the first state, so a reload mid-question stays quiet
    let revealTimer = 0;
    const sync = KBCSync.start({
        pin,
        interval: Number(room.dataset.interval) || 1500,
        onPoll: applyTimer,
        onState: (s) => {
            const qid = s.current_question?.id ?? null;
            if (qid !== lastQid) { typed = ''; typedItems = []; lastQid = qid; }

            // A new question appeared (not just a page reload mid-question): fanfare.
            if (audioQid !== undefined && qid !== null && qid !== audioQid && s.status === 'active' && s.phase === 'open') {
                KBCAudio.play('present');
            }
            audioQid = qid;

            // Host just closed the question I answered: flash my choice orange, then reveal.
            const closing = prev && prev.phase !== 'revealed' && s.phase === 'revealed' && prev.status === 'active'
                && qid !== null && qid === prev.current_question?.id && s.my_answer?.submitted;
            prev = s;
            clearTimeout(revealTimer);
            if (closing) {
                const { reveal, ...beforeReveal } = s;
                render(beforeReveal);   // chosen option stays orange; no verdict yet
                revealTimer = setTimeout(() => {
                    render(s);
                    KBCAudio.play(s.my_answer.correct ? 'correct' : 'wrong');   // the verdict lands with the colours
                }, REVEAL_DELAY_MS);
            } else {
                render(s);
            }
        },
        onConnection: (ok) => { $('conn-banner').hidden = ok; },
        onFatal: () => { window.location.href = base + 'index.php'; },
    });
})();
