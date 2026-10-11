/**
 * Renders the picture / audio part of a question for the host and player screens.
 * Both pages re-render on every poll that changes, so the media is only rebuilt when the
 * question (or its media) changes. Otherwise audio would restart each time someone answers.
 */
window.KBCMedia = (() => {
    'use strict';

    function imageNode(src) {
        const wrap = document.createElement('figure');
        wrap.className = 'media-figure';
        const img = document.createElement('img');
        img.className = 'media-img';
        img.alt = 'Question picture';
        img.referrerPolicy = 'no-referrer';
        img.decoding = 'async';
        img.addEventListener('error', () => {
            wrap.replaceChildren(Object.assign(document.createElement('p'), {
                className: 'media-error', textContent: 'The picture could not be loaded.',
            }));
        });
        img.src = src;
        wrap.append(img);
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

    /** question = { id, media, audio }; box = container element. */
    function render(box, q, { autoplay = false } = {}) {
        const key = `${q.id}|${q.media || ''}|${q.audio || ''}`;
        if (box.dataset.key === key) return;
        box.dataset.key = key;
        box.replaceChildren();
        box.hidden = !(q.media || q.audio);
        if (q.media) box.append(imageNode(q.media));
        if (q.audio) box.append(audioNode(q.audio, autoplay));
    }

    function clear(box) {
        box.dataset.key = '';
        box.replaceChildren();
        box.hidden = true;
    }

    return { render, clear };
})();
