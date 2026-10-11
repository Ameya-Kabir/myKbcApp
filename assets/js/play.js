(() => {
    'use strict';

    const base = document.body.dataset.base || '';
    const room = document.querySelector('.room');
    const $ = (id) => document.getElementById(id);
    const screens = ['screen-wait', 'screen-question', 'screen-over'];

    function show(id) { screens.forEach((s) => { $(s).hidden = s !== id; }); }

    // Players are slaves to the host: whatever status the server reports decides the screen.
    function render(s) {
        if (s.status === 'lobby') {
            show('screen-wait');
            $('player-list').replaceChildren(...s.players.map((p) => {
                const li = document.createElement('li');
                li.textContent = p.name;
                return li;
            }));
            $('wait-sub').textContent = `${s.player_count} player${s.player_count === 1 ? '' : 's'} in the room.`;
        } else if (s.status === 'active') {
            show('screen-question');   // question rendering arrives with the next build step
        } else {
            show('screen-over');
        }
    }

    KBCSync.start({
        pin: room.dataset.pin,
        interval: Number(room.dataset.interval) || 1500,
        onState: render,
        onConnection: (ok) => { $('conn-banner').hidden = ok; },
        onFatal: () => { window.location.href = base + 'index.php'; },
    });
})();
