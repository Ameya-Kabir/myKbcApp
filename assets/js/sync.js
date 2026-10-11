/**
 * Short-polling client shared by host.js and play.js.
 *  - setInterval tick, with an in-flight guard so slow responses never pile up
 *  - sends the version it already has; the server answers {unchanged:true} when nothing moved
 *  - request timeout + backoff after failures, slower while the tab is hidden
 */
window.KBCSync = (() => {
    'use strict';

    function start({ pin, interval, onState, onConnection, onFatal }) {
        const base = document.body.dataset.base || '';
        let version = -1;
        let timer = 0;
        let inFlight = false;
        let fails = 0;
        let stopped = false;
        let currentDelay = 0;

        function wantedDelay() {
            let d = interval;
            if (document.hidden) d *= 3;                    // background tab: poll less
            if (fails >= 3) d = Math.min(d * 3, 8000);      // server trouble: back off
            return d;
        }
        function schedule() {
            const d = wantedDelay();
            if (d === currentDelay && timer) return;
            clearInterval(timer);
            currentDelay = d;
            timer = setInterval(tick, d);
        }

        async function tick() {
            if (inFlight || stopped) return;
            inFlight = true;
            const ctrl = new AbortController();
            const kill = setTimeout(() => ctrl.abort(), 5000);
            try {
                const res = await fetch(`${base}api/sync_state.php?pin=${encodeURIComponent(pin)}&v=${version}`, {
                    credentials: 'same-origin', cache: 'no-store', signal: ctrl.signal,
                });
                const data = await res.json();
                if (!data.ok) {
                    if (res.status === 401 || data.code === 'no_room' || data.code === 'not_member') {
                        stop();
                        onFatal?.(data);
                        return;
                    }
                    throw new Error(data.error || 'bad response');
                }
                if (fails) onConnection?.(true);
                fails = 0;
                if (!data.unchanged) {
                    version = data.version;
                    onState(data);
                }
            } catch {
                fails++;
                if (fails === 2) onConnection?.(false);
            } finally {
                clearTimeout(kill);
                inFlight = false;
                if (!stopped) schedule();
            }
        }

        function stop() { stopped = true; clearInterval(timer); }
        document.addEventListener('visibilitychange', () => { if (!stopped) { schedule(); if (!document.hidden) tick(); } });

        schedule();
        tick();   // don't wait a full interval for the first state
        return { stop, refresh: tick };
    }

    return { start };
})();
