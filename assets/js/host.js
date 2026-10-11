(() => {
    'use strict';

    const base = document.body.dataset.base || '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const room = document.querySelector('.room');
    const pin = room.dataset.pin;
    const $ = (id) => document.getElementById(id);
    let sync;
    let state = null;

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

    function render(s) {
        state = s;
        const list = $('player-list');
        list.replaceChildren(...s.players.map((p) => {
            const li = document.createElement('li');
            li.textContent = p.name;
            return li;
        }));
        $('player-count').textContent = String(s.player_count);
        $('no-players').hidden = s.player_count > 0;

        const start = $('start-btn');
        if (s.status === 'lobby') {
            $('room-status').textContent = s.player_count
                ? `${s.player_count} player${s.player_count === 1 ? '' : 's'} ready`
                : 'Waiting for players to join';
            start.hidden = false;
            start.disabled = s.player_count < 1;
        } else if (s.status === 'active') {
            start.hidden = true;
            $('room-status').textContent = 'Game in progress';
        } else {
            start.hidden = true;
            $('room-status').textContent = 'This game has finished';
        }
    }

    $('start-btn').addEventListener('click', async (ev) => {
        const btn = ev.currentTarget;
        const err = $('host-error');
        err.hidden = true;
        btn.disabled = true;
        const res = await post('api/host_controls.php', { action: 'start_game', pin });
        if (!res.ok) {
            err.textContent = res.error || 'Could not start the game.';
            err.hidden = false;
            btn.disabled = !state || state.player_count < 1;
            return;
        }
        sync.refresh();   // show the new state right away instead of waiting for the next tick
    });

    sync = KBCSync.start({
        pin,
        interval: Number(room.dataset.interval) || 1500,
        onState: render,
        onConnection: (ok) => { $('conn-banner').hidden = ok; },
        onFatal: () => { window.location.href = base + 'index.php'; },
    });
})();
