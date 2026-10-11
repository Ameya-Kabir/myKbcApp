/**
 * Game-show audio for the host and player screens.
 *
 *  - Preloads the seven sounds in assets/audio/ (see README-AUDIO.md). Empty or missing files are
 *    tolerated: that sound is simply skipped, nothing throws.
 *  - Two kinds of sound: LOOPS (welcome, timer) that stay on until told otherwise, and one-shot
 *    STINGERS (present, lock, timeout, correct, wrong). Playing a stinger cuts any other stinger,
 *    so effects never pile up; the "present" stinger quietly ducks the ticking timer underneath it.
 *  - Browsers refuse sound until the user has touched the page. Requests made earlier are
 *    remembered (loops) and start on the first tap; that tap also "unlocks" every sound for Safari.
 *  - A floating mute button (remembered in localStorage) is added to every page that loads this.
 */
window.KBCAudio = (() => {
    'use strict';

    const base = document.body.dataset.base || '';
    const SOUNDS = {
        welcome: { volume: 0.45, loop: true },     // lobby / waiting room
        present: { volume: 0.9 },                  // a new question appears
        timer:   { volume: 0.6, loop: true },      // suspenseful ticking while the clock runs
        lock:    { volume: 0.9 },                  // a player locks in an answer
        timeout: { volume: 0.9 },                  // time ran out
        correct: { volume: 1 },                    // reveal: right answer
        wrong:   { volume: 1 },                    // reveal: wrong answer
    };
    const STINGERS = ['present', 'lock', 'timeout', 'correct', 'wrong'];
    const DUCKED_TIMER_VOLUME = 0.2;

    const els = {};                 // name -> HTMLAudioElement
    const broken = new Set();       // files that failed to load (empty placeholder, 404, bad format)
    const wanted = new Set();       // loops that should be playing whenever sound is allowed
    const live = new Set();         // sounds currently started by us
    let muted = false;
    let unlocked = false;           // has the user touched the page yet?
    let blocked = false;            // did the browser refuse a play() for lack of a touch?
    let button = null;

    try { muted = localStorage.getItem('kbc-muted') === '1'; } catch { /* storage unavailable */ }

    function restoreTimerVolume() { els.timer.volume = SOUNDS.timer.volume; }

    Object.entries(SOUNDS).forEach(([name, cfg]) => {
        const a = new Audio();
        a.preload = 'auto';
        a.loop = !!cfg.loop;
        a.volume = cfg.volume;
        a.addEventListener('error', () => broken.add(name));
        a.addEventListener('ended', () => live.delete(name));
        a.addEventListener('pause', () => live.delete(name));
        a.src = `${base}assets/audio/${name}.mp3`;
        els[name] = a;
    });
    ['ended', 'pause', 'error'].forEach((ev) => els.present.addEventListener(ev, restoreTimerVolume));

    function setBlocked(v) {
        blocked = v;
        refreshButton();
    }

    function start(name) {
        const a = els[name];
        if (!a || muted || broken.has(name)) return;
        a.muted = false;
        live.add(name);
        let p;
        try { p = a.play(); } catch { live.delete(name); return; }
        if (p && p.catch) {
            p.catch((err) => {
                live.delete(name);
                if (err && err.name === 'NotAllowedError') setBlocked(true);   // retried on the first tap
            });
        }
    }

    function halt(name) {
        const a = els[name];
        if (!a) return;
        live.delete(name);
        try { a.pause(); a.currentTime = 0; } catch { /* not loaded yet */ }
    }

    /** Play a one-shot sound from the start. Other one-shots are cut so effects never overlap. */
    function play(name) {
        const a = els[name];
        if (!a || !SOUNDS[name]) return;
        if (STINGERS.includes(name)) STINGERS.forEach((n) => { if (n !== name) halt(n); });
        try { a.currentTime = 0; } catch { /* not loaded yet */ }
        if (name === 'present') els.timer.volume = DUCKED_TIMER_VOLUME;   // restored when it ends
        start(name);
    }

    /** Switch a looping sound on or off. Stays "wanted" while muted or not yet unlocked. */
    function loop(name, on) {
        if (!SOUNDS[name] || !SOUNDS[name].loop) return;
        if (on) {
            wanted.add(name);
            if (els[name].paused) start(name);
        } else {
            wanted.delete(name);
            halt(name);
        }
    }

    function applyLoops() {
        if (muted) return;
        wanted.forEach((n) => { if (els[n].paused) start(n); });
    }

    function stopAll() {
        wanted.clear();
        Object.keys(els).forEach(halt);
    }

    function setMuted(m) {
        muted = !!m;
        try { localStorage.setItem('kbc-muted', muted ? '1' : '0'); } catch { /* ignore */ }
        if (muted) Object.keys(els).forEach(halt);   // loops stay "wanted" and resume on unmute
        else applyLoops();
        refreshButton();
    }

    /**
     * First touch on the page: let every sound play once (silently) so Safari/iOS allow later
     * playback from timers and polls. Anything the game started in the meantime is left alone.
     */
    function unlock() {
        if (unlocked) return;
        unlocked = true;
        const jobs = Object.entries(els).filter(([n, a]) => a.paused && !broken.has(n)).map(([n, a]) => {
            a.muted = true;
            let p;
            try { p = a.play(); } catch { a.muted = false; return Promise.resolve(); }
            return Promise.resolve(p)
                .then(() => { if (!live.has(n)) { a.pause(); a.currentTime = 0; } })
                .catch(() => {})
                .then(() => { a.muted = false; });
        });
        Promise.all(jobs).then(() => { setBlocked(false); applyLoops(); });
    }
    ['pointerdown', 'touchstart', 'keydown', 'click'].forEach((ev) =>
        document.addEventListener(ev, unlock, { capture: true, passive: true }));

    // ----- Mute button -----
    const ICON_ON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4z"/><path class="wave" d="M16 8.5a5 5 0 0 1 0 7M18.5 6a8.5 8.5 0 0 1 0 12" fill="none"/></svg>';
    const ICON_OFF = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4z"/><path class="slash" d="M17 9l5 6M22 9l-5 6" fill="none"/></svg>';

    function refreshButton() {
        if (!button) return;
        button.innerHTML = muted ? ICON_OFF : ICON_ON;
        button.setAttribute('aria-pressed', String(muted));
        const label = muted ? 'Unmute sound' : (blocked && !unlocked ? 'Tap anywhere to enable sound' : 'Mute sound');
        button.setAttribute('aria-label', label);
        button.title = label;
        button.classList.toggle('is-muted', muted);
        button.classList.toggle('needs-tap', !muted && blocked && !unlocked);
    }

    function mountButton() {
        button = Object.assign(document.createElement('button'), { type: 'button', id: 'sound-toggle', className: 'sound-toggle' });
        button.addEventListener('click', () => setMuted(!muted));
        document.body.append(button);
        refreshButton();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountButton);
    else mountButton();

    return {
        play, loop, stopAll, setMuted,
        toggle: () => setMuted(!muted),
        isMuted: () => muted,
        /** For tests and debugging. */
        state: () => ({ muted, unlocked, blocked, wanted: [...wanted], live: [...live], broken: [...broken] }),
    };
})();
