(() => {
    'use strict';

    const base = document.body.dataset.base || '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const main = document.querySelector('main');
    const authed = main?.dataset.authed === '1';
    const form = document.getElementById('join-form');
    const input = document.getElementById('pin-input');
    const errBox = document.getElementById('join-error');
    const PENDING = 'kbc-pending-pin';

    const store = {
        get() { try { return sessionStorage.getItem(PENDING) || ''; } catch { return ''; } },
        set(v) { try { sessionStorage.setItem(PENDING, v); } catch { /* storage unavailable */ } },
        clear() { try { sessionStorage.removeItem(PENDING); } catch { /* ignore */ } },
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
    const show = (el, text) => { if (el) { el.textContent = text; el.hidden = !text; } };
    const digits = (s) => s.replace(/\D/g, '').slice(0, 6);

    // "482913" -> "482 913" while typing
    input?.addEventListener('input', () => {
        const d = digits(input.value);
        input.value = d.length > 3 ? d.slice(0, 3) + ' ' + d.slice(3) : d;
    });

    async function join(pin) {
        show(errBox, '');
        const res = await post('api/player_join.php', { pin });
        if (res.ok) { store.clear(); window.location.href = base + res.redirect; return; }
        show(errBox, res.error || 'Could not join that room.');
    }

    form?.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const pin = digits(input.value);
        if (pin.length !== 6) { show(errBox, 'Enter the 6-digit room PIN.'); input.focus(); return; }

        if (authed) { join(pin); return; }

        // Not logged in: remember the PIN, ask for an account, finish joining after login.
        store.set(pin);
        show(errBox, '');
        const note = document.getElementById('join-note');
        show(note, `Log in or create an account to join room ${pin.slice(0, 3)} ${pin.slice(3)}.`);
        document.getElementById('tab-login')?.click();
    });

    // ----- Join / Host tabs (logged-in home screen) -----
    const tabs = [...document.querySelectorAll('.home-tab')];
    function selectTab(tab, focus = false) {
        tabs.forEach((t) => {
            const on = t === tab;
            t.classList.toggle('is-active', on);
            t.parentElement.classList.toggle('is-active', on);   // glow sits on the wrapper (clip-path hides box-shadow)
            t.setAttribute('aria-selected', String(on));
            t.tabIndex = on ? 0 : -1;
            document.getElementById(t.getAttribute('aria-controls')).hidden = !on;   // show one form, hide the other
        });
        if (focus) tab.focus();
    }
    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => selectTab(tab));
        tab.addEventListener('keydown', (ev) => {   // arrow keys move between tabs, as screen-reader users expect
            const move = { ArrowRight: 1, ArrowLeft: -1 }[ev.key];
            if (move) { ev.preventDefault(); selectTab(tabs[(i + move + tabs.length) % tabs.length], true); }
            if (ev.key === 'Home') { ev.preventDefault(); selectTab(tabs[0], true); }
            if (ev.key === 'End') { ev.preventDefault(); selectTab(tabs[tabs.length - 1], true); }
        });
    });

    // Back from login (or a ?pin= link while logged in): join automatically.
    if (authed) {
        const pending = store.get() || main.dataset.prefill || '';
        if (/^\d{6}$/.test(pending)) { store.clear(); join(pending); }

        document.getElementById('host-form')?.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const f = ev.currentTarget;
            const err = document.getElementById('host-error');
            show(err, '');
            if (!f.checkValidity()) { f.reportValidity(); return; }
            const btn = f.querySelector('button[type="submit"]');
            btn.disabled = true;
            const res = await post('api/host_controls.php', {
                action: 'create_room',
                points_correct: f.elements.points_correct.value,
                points_wrong: f.elements.points_wrong.value,
                timer_seconds: f.elements.timer_seconds.value,
            });
            btn.disabled = false;
            if (res.ok) { window.location.href = base + res.redirect; return; }
            show(err, res.error || 'Could not create the room.');
        });
    } else if (main?.dataset.prefill) {
        input.value = main.dataset.prefill.slice(0, 3) + ' ' + main.dataset.prefill.slice(3);
    }
})();
