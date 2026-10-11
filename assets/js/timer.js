/**
 * Countdown bar shared by the host and player screens.
 * It is a display only: the server decides when time is up. The bar is anchored to the
 * `remaining_time` the server sends with every poll, so it re-syncs about every 1.5 s and
 * cannot drift. When it reaches zero it calls onExpire once per question.
 */
window.KBCTimer = (() => {
    'use strict';

    function create(root, { onExpire } = {}) {
        const fill = root.querySelector('.timebar-fill');
        const num = root.querySelector('.timebar-num');
        let raf = 0;
        let endAt = 0;
        let total = 1;
        let key = null;       // identifies the countdown (question + stage); a new key re-arms onExpire
        let expired = false;

        function paint(left) {
            const secs = Math.ceil(left);
            fill.style.transform = `scaleX(${Math.max(0, Math.min(1, left / total))})`;
            num.textContent = String(secs);
            root.classList.toggle('is-warn', left > 5 && (left <= 10 || left / total <= 0.33));
            root.classList.toggle('is-danger', left <= 5 && left > 0);
            root.classList.toggle('is-out', left <= 0);
        }

        function finish() {
            cancelAnimationFrame(raf);
            raf = 0;
            if (!expired) {
                expired = true;
                if (onExpire) onExpire();
            }
        }

        function frame(now) {
            const left = Math.max(0, (endAt - now) / 1000);
            paint(left);
            if (left <= 0) { finish(); return; }
            raf = requestAnimationFrame(frame);
        }

        return {
            /** Start or re-anchor the countdown to `remaining` seconds left of `totalSecs`. */
            run(remaining, totalSecs, k) {
                if (k !== key) { key = k; expired = false; }
                if (remaining > 0) expired = false;   // the server says time is left: re-arm
                total = Math.max(1, totalSecs);
                endAt = performance.now() + remaining * 1000;
                root.hidden = false;
                root.classList.remove('is-paused');
                if (remaining <= 0) { paint(0); finish(); return; }
                if (!raf) raf = requestAnimationFrame(frame);
            },
            /** Stop ticking but keep showing the last value (e.g. everyone answered early). */
            freeze() {
                cancelAnimationFrame(raf);
                raf = 0;
                root.classList.add('is-paused');
            },
            hide() {
                cancelAnimationFrame(raf);
                raf = 0;
                key = null;
                expired = false;
                root.hidden = true;
                root.classList.remove('is-warn', 'is-danger', 'is-out', 'is-paused');
            },
        };
    }

    return { create };
})();
