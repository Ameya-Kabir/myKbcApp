/** Tiny confetti burst: falling coloured <div>s, no libraries. Skipped for reduced-motion users. */
window.KBCConfetti = (() => {
    'use strict';

    const COLORS = ['#ffe08a', '#f2b632', '#ff9a1f', '#f6efdc', '#8be0a5', '#7fb2ff', '#ff8fa3', '#c9a2ff'];
    const rand = (a, b) => a + Math.random() * (b - a);

    function burst({ count = 140, duration = 5200 } = {}) {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const layer = document.createElement('div');
        layer.className = 'confetti-layer';
        layer.setAttribute('aria-hidden', 'true');

        let longest = 0;
        for (let i = 0; i < count; i++) {
            const piece = document.createElement('i');
            const fall = rand(duration * 0.55, duration);
            const delay = rand(0, duration * 0.35);
            longest = Math.max(longest, fall + delay);
            piece.style.cssText = [
                `left:${rand(0, 100).toFixed(2)}%`,
                `width:${rand(6, 12).toFixed(1)}px`,
                `height:${rand(10, 18).toFixed(1)}px`,
                `background:${COLORS[(Math.random() * COLORS.length) | 0]}`,
                `--dx:${rand(-18, 18).toFixed(1)}vw`,
                `--rot:${rand(360, 1080).toFixed(0)}deg`,
                `animation-duration:${fall.toFixed(0)}ms`,
                `animation-delay:${delay.toFixed(0)}ms`,
            ].join(';');
            layer.append(piece);
        }
        document.body.append(layer);
        setTimeout(() => layer.remove(), longest + 300);
    }

    return { burst };
})();
