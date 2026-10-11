/**
 * Renders the picture / audio / memory parts of a question for the host and player screens.
 * Both pages re-render on every poll that changes, so media is only rebuilt when the question
 * (or its media) changes. Otherwise audio would restart each time someone answers.
 *
 * Type C hides the picture behind a 4x4 grid of cover tiles; the host's reveal stage decides
 * how many tiles are open (stage 0/1/2/3 = 0/25/50/100%). Tile order is a shuffle seeded by the
 * question id, so every screen in the room opens the same tiles. NOTE: this is a visual effect.
 * The picture URL is still in the page, so it deters casual peeking, not a determined player.
 */
window.KBCMedia = (() => {
    'use strict';

    const GRID = 16;                       // 4 x 4 tiles
    const TILES_OPEN = [0, 4, 8, 16];      // by reveal_stage

    function seededOrder(seed) {
        // mulberry32 -> Fisher-Yates, deterministic per question
        let a = (seed * 2654435761) >>> 0;
        const rnd = () => {
            a = (a + 0x6D2B79F5) >>> 0;
            let t = a;
            t = Math.imul(t ^ (t >>> 15), t | 1);
            t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
        const order = Array.from({ length: GRID }, (_, i) => i);
        for (let i = GRID - 1; i > 0; i--) {
            const j = Math.floor(rnd() * (i + 1));
            [order[i], order[j]] = [order[j], order[i]];
        }
        return order;   // order[k] = tile index opened k-th
    }

    function imageNode(src, qid, progressive) {
        const wrap = document.createElement('figure');
        wrap.className = 'media-figure';
        const box = document.createElement('div');
        box.className = 'reveal-box';
        const img = document.createElement('img');
        img.className = 'media-img';
        img.alt = progressive ? 'Hidden picture, being revealed' : 'Question picture';
        img.referrerPolicy = 'no-referrer';
        img.decoding = 'async';
        img.addEventListener('error', () => {
            wrap.replaceChildren(Object.assign(document.createElement('p'), {
                className: 'media-error', textContent: 'The picture could not be loaded.',
            }));
        });
        img.src = src;
        box.append(img);

        if (progressive) {
            const tiles = document.createElement('div');
            tiles.className = 'reveal-tiles';
            tiles.setAttribute('aria-hidden', 'true');
            const rank = new Array(GRID);
            seededOrder(qid).forEach((tileIndex, k) => { rank[tileIndex] = k; });
            for (let i = 0; i < GRID; i++) {
                const t = document.createElement('span');
                t.className = 'tile';
                t.dataset.rank = String(rank[i]);
                t.style.transitionDelay = `${(rank[i] % 4) * 90}ms`;   // small stagger within a step
                tiles.append(t);
            }
            box.append(tiles);
        }
        wrap.append(box);
        return wrap;
    }

    function audioNode(src, autoplay) {
        const card = document.createElement('div');
        card.className = 'audio-card';
        const label = Object.assign(document.createElement('span'), { className: 'audio-label', textContent: 'Listen' });

        const audio = document.createElement('audio');
        audio.controls = true;
        audio.preload = 'auto';
        audio.src = src;

        const tap = Object.assign(document.createElement('button'), {
            type: 'button', className: 'btn btn-gold audio-tap', textContent: 'Tap to play', hidden: true,
        });
        tap.addEventListener('click', () => { audio.play().catch(() => {}); });
        audio.addEventListener('play', () => { tap.hidden = true; });
        audio.addEventListener('error', () => {
            card.replaceChildren(Object.assign(document.createElement('p'), {
                className: 'media-error', textContent: 'The audio could not be loaded.',
            }));
        });

        card.append(label, audio, tap);
        if (autoplay) {
            // Browsers often block autoplay until the page has had a tap; show a big button if so.
            const p = audio.play();
            if (p && p.catch) p.catch(() => { tap.hidden = false; });
        }
        return card;
    }

    /** Open the right number of cover tiles for a type C picture. */
    function applyReveal(box, q, open) {
        const tiles = box.querySelector('.reveal-tiles');
        if (!tiles) return;
        const count = open ? (TILES_OPEN[q.reveal_stage] ?? 0) : GRID;   // closed question: show it all
        tiles.querySelectorAll('.tile').forEach((t) => t.classList.toggle('is-open', Number(t.dataset.rank) < count));
        const pct = Math.round((count / GRID) * 100);
        let badge = box.querySelector('.reveal-badge');
        if (!badge) {
            badge = Object.assign(document.createElement('p'), { className: 'reveal-badge' });
            box.append(badge);
        }
        badge.textContent = pct >= 100 ? 'Fully revealed' : `${pct}% revealed`;
    }

    /**
     * question = { id, type, media, audio, reveal_stage }; box = container element.
     * opts: autoplay (try to start audio when first built), open (question accepting answers).
     */
    function render(box, q, { autoplay = false, open = true } = {}) {
        const key = `${q.id}|${q.media || ''}|${q.audio || ''}`;
        if (box.dataset.key !== key) {
            box.dataset.key = key;
            box.replaceChildren();
            box.hidden = !(q.media || q.audio);
            if (q.media) {
                const fig = imageNode(q.media, q.id, q.type === 'C');
                box.append(fig);
            }
            if (q.audio) box.append(audioNode(q.audio, autoplay));
        }
        if (q.type === 'C') applyReveal(box, q, open);   // stage changes update in place, with a transition
    }

    function clear(box) {
        box.dataset.key = '';
        box.replaceChildren();
        box.hidden = true;
    }

    /** Memory game phase 1: numbered cards, in order. Rebuilt only when the item list changes. */
    function renderItems(box, items) {
        if (!items || !items.length) { box.dataset.key = ''; box.replaceChildren(); box.hidden = true; return; }
        const key = items.join('\u0001');
        if (box.dataset.key === key) { box.hidden = false; return; }
        box.dataset.key = key;
        box.hidden = false;
        const list = document.createElement('ol');
        list.className = 'memory-grid';
        items.forEach((text, i) => {
            const li = document.createElement('li');
            li.className = 'hex-wrap';
            li.style.animationDelay = `${i * 110}ms`;
            const card = document.createElement('div');
            card.className = 'hex memory-card';
            card.append(Object.assign(document.createElement('span'), { className: 'memory-n', textContent: String(i + 1) }),
                        Object.assign(document.createElement('span'), { className: 'memory-text', textContent: text }));
            li.append(card);
            list.append(li);
        });
        box.replaceChildren(list);
    }

    return { render, clear, renderItems };
})();
