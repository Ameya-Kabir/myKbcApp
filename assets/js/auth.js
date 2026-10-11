(() => {
    'use strict';

    const base = document.body.dataset.base || '';
    let csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    async function post(url, data) {
        const res = await fetch(base + url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin',
            body: JSON.stringify(data),
        });
        let json;
        try { json = await res.json(); } catch { json = { ok: false, error: 'Unexpected server response.' }; }
        return json;
    }

    function showMsg(el, text) {
        el.textContent = text;
        el.hidden = !text;
    }

    // ---- Login / register ----
    const tabs = document.querySelectorAll('.tab');
    const forms = { login: document.getElementById('form-login'), register: document.getElementById('form-register') };
    tabs.forEach((tab) => tab.addEventListener('click', () => {
        const which = tab.id === 'tab-login' ? 'login' : 'register';
        tabs.forEach((t) => {
            const on = t === tab;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on);
        });
        forms.login.hidden = which !== 'login';
        forms.register.hidden = which !== 'register';
        forms[which].querySelector('input').focus();
    }));

    document.querySelectorAll('.auth-form').forEach((form) => {
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const err = form.querySelector('.form-error');
            const btn = form.querySelector('button[type="submit"]');
            showMsg(err, '');
            form.querySelectorAll('input').forEach((i) => i.removeAttribute('aria-invalid'));

            const data = Object.fromEntries(new FormData(form));
            data.action = form.dataset.action;
            if (!form.checkValidity()) { form.reportValidity(); return; }

            btn.disabled = true;
            const res = await post('api/auth.php', data);
            btn.disabled = false;

            if (res.ok) { window.location.href = base + res.redirect; return; }
            showMsg(err, res.error || 'Something went wrong.');
            if (res.field) form.querySelector(`[name="${res.field}"]`)?.setAttribute('aria-invalid', 'true');
        });
    });

    // ---- Logout ----
    document.getElementById('logout-btn')?.addEventListener('click', async () => {
        const res = await post('api/auth.php', { action: 'logout' });
        window.location.href = base + (res.redirect || 'index.php');
    });

    // ---- Dashboard settings ----
    const sform = document.getElementById('settings-form');
    if (sform) {
        const msg = document.getElementById('settings-msg');
        const readSettings = () => {
            const d = {};
            sform.querySelectorAll('input').forEach((i) => {
                d[i.name] = i.type === 'checkbox' ? i.checked : i.value;
            });
            return d;
        };
        const save = async () => {
            if (!sform.checkValidity()) { sform.reportValidity(); return false; }
            const res = await post('api/save_settings.php', readSettings());
            showMsg(msg, res.ok ? 'Settings saved.' : (res.error || 'Could not save settings.'));
            msg.classList.toggle('is-error', !res.ok);
            return res.ok;
        };
        sform.addEventListener('submit', (ev) => { ev.preventDefault(); save(); });
        document.getElementById('play-btn').addEventListener('click', async (ev) => {
            if (await save()) window.location.href = base + ev.currentTarget.dataset.href;
        });
        // Dim the "uses" field when a lifeline is off.
        sform.querySelectorAll('.lifeline').forEach((row) => {
            const toggle = row.querySelector('input[type="checkbox"]');
            const uses = row.querySelector('.uses input');
            const sync = () => { uses.disabled = !toggle.checked; };
            toggle.addEventListener('change', sync);
            sync();
        });
    }
})();
