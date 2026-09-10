/**
 * CRM front-end behaviours. Vanilla ES, progressive enhancement — every page
 * works without this file. No framework. Loaded with a CSP nonce.
 */
(function () {
    'use strict';

    /* ---- Mobile navigation drawer -------------------------------------- */
    function initNav() {
        const toggle = document.querySelector('[data-nav-toggle]');
        const drawer = document.querySelector('[data-nav-drawer]');
        const backdrop = document.querySelector('[data-nav-backdrop]');
        if (!toggle || !drawer) return;

        const open = () => { drawer.dataset.open = 'true'; backdrop && (backdrop.hidden = false); };
        const close = () => { delete drawer.dataset.open; backdrop && (backdrop.hidden = true); };

        toggle.addEventListener('click', () => (drawer.dataset.open ? close() : open()));
        backdrop && backdrop.addEventListener('click', close);
        document.addEventListener('keydown', (e) => e.key === 'Escape' && close());
    }

    /* ---- Dropdown menus ([data-dropdown] > [data-dropdown-trigger] + [data-dropdown-menu]) */
    function initDropdowns() {
        document.querySelectorAll('[data-dropdown]').forEach((root) => {
            const trigger = root.querySelector('[data-dropdown-trigger]');
            const menu = root.querySelector('[data-dropdown-menu]');
            if (!trigger || !menu) return;

            const close = () => { menu.hidden = true; trigger.setAttribute('aria-expanded', 'false'); };
            const toggle = () => {
                const willOpen = menu.hidden;
                document.querySelectorAll('[data-dropdown-menu]').forEach((m) => (m.hidden = true));
                menu.hidden = !willOpen;
                trigger.setAttribute('aria-expanded', String(willOpen));
            };

            trigger.addEventListener('click', (e) => { e.stopPropagation(); toggle(); });
            document.addEventListener('click', (e) => { if (!root.contains(e.target)) close(); });
            document.addEventListener('keydown', (e) => e.key === 'Escape' && close());
        });
    }

    /* ---- Modals ([data-modal-open="id"] / <dialog id="id" data-modal>) --- */
    function initModals() {
        document.querySelectorAll('[data-modal-open]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const dlg = document.getElementById(btn.dataset.modalOpen);
                if (dlg && typeof dlg.showModal === 'function') dlg.showModal();
            });
        });
        document.querySelectorAll('[data-modal] [data-modal-close]').forEach((btn) => {
            btn.addEventListener('click', () => btn.closest('dialog')?.close());
        });
    }

    /* ---- Confirm before submitting/navigating ([data-confirm="msg"]) ---- */
    function initConfirms() {
        document.body.addEventListener('click', (e) => {
            const el = e.target.closest('[data-confirm]');
            if (el && !window.confirm(el.dataset.confirm)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
        document.body.addEventListener('submit', (e) => {
            const form = e.target;
            if (form.matches('[data-confirm]') && !window.confirm(form.dataset.confirm)) {
                e.preventDefault();
            }
        });
    }

    /* ---- Toasts: auto-dismiss + close button --------------------------- */
    function initToasts() {
        document.querySelectorAll('[data-toast]').forEach((toast) => {
            const remove = () => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 200); };
            toast.querySelector('[data-toast-close]')?.addEventListener('click', remove);
            const ttl = parseInt(toast.dataset.toast, 10);
            if (ttl > 0) setTimeout(remove, ttl);
        });
    }

    /* ---- Submit-once guard on forms ([data-once]) ---------------------- */
    function initSubmitGuards() {
        document.querySelectorAll('form[data-once]').forEach((form) => {
            form.addEventListener('submit', () => {
                const btn = form.querySelector('button[type="submit"], [type="submit"]');
                if (btn) { btn.disabled = true; btn.dataset.loading = 'true'; }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initNav();
        initDropdowns();
        initModals();
        initConfirms();
        initToasts();
        initSubmitGuards();
        initPasskeys();
    });

    /* ---- WebAuthn / passkeys ------------------------------------------- *
     * Progressive enhancement over server-verified ceremonies. Buttons opt in
     * with data attributes:
     *   [data-passkey-register]  -> register a new credential (JSON POST)
     *   [data-passkey-login]     -> passwordless sign-in
     *   [data-passkey-2fa]       -> passkey as the second factor
     * Each button carries data-options-url and data-verify-url.
     */
    const b64urlToBuf = (s) => {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        s += '='.repeat((4 - (s.length % 4)) % 4);
        const bin = atob(s);
        const buf = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf.buffer;
    };
    const bufToB64url = (buf) => {
        const bytes = new Uint8Array(buf);
        let bin = '';
        for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    };
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf(), 'Accept': 'application/json' },
            body: JSON.stringify(body || {}),
            credentials: 'same-origin',
        });
        let data = {};
        try { data = await res.json(); } catch (e) { /* non-JSON */ }
        if (!res.ok) {
            if (data && data.confirm_required && data.confirm_url) {
                window.location.assign(data.confirm_url);
                throw new Error('Password confirmation required.');
            }
            if (data && data.twofa_required && data.setup_url) {
                window.location.assign(data.setup_url);
                throw new Error('Two-factor setup required.');
            }
            throw new Error(data.error || 'Request failed (' + res.status + ')');
        }
        return data;
    }

    function feedback(el, message, ok) {
        const target = el.getAttribute('data-passkey-status');
        const box = target ? document.getElementById(target) : null;
        if (box) {
            box.textContent = message;
            box.dataset.state = ok ? 'ok' : 'error';
            box.hidden = false;
        } else if (!ok) {
            window.alert(message);
        }
    }

    function toCreateOptions(o) {
        o.challenge = b64urlToBuf(o.challenge);
        o.user.id = b64urlToBuf(o.user.id);
        (o.excludeCredentials || []).forEach((c) => { c.id = b64urlToBuf(c.id); });
        return o;
    }
    function toGetOptions(o) {
        o.challenge = b64urlToBuf(o.challenge);
        (o.allowCredentials || []).forEach((c) => { c.id = b64urlToBuf(c.id); });
        return o;
    }
    function serializeAttestation(cred) {
        return {
            id: cred.id,
            rawId: bufToB64url(cred.rawId),
            type: cred.type,
            response: {
                clientDataJSON: bufToB64url(cred.response.clientDataJSON),
                attestationObject: bufToB64url(cred.response.attestationObject),
                transports: cred.response.getTransports ? cred.response.getTransports() : [],
            },
        };
    }
    function serializeAssertion(cred) {
        return {
            id: cred.id,
            rawId: bufToB64url(cred.rawId),
            type: cred.type,
            response: {
                clientDataJSON: bufToB64url(cred.response.clientDataJSON),
                authenticatorData: bufToB64url(cred.response.authenticatorData),
                signature: bufToB64url(cred.response.signature),
                userHandle: cred.response.userHandle ? bufToB64url(cred.response.userHandle) : null,
            },
        };
    }

    async function doRegister(btn) {
        const options = toCreateOptions(await postJson(btn.dataset.optionsUrl, {}));
        const cred = await navigator.credentials.create({ publicKey: options });
        if (!cred) throw new Error('No credential was created.');
        const label = (btn.dataset.passkeyLabelFrom
            ? document.getElementById(btn.dataset.passkeyLabelFrom)?.value : '') || 'Security key';
        await postJson(btn.dataset.verifyUrl, { credential: serializeAttestation(cred), label });
        feedback(btn, 'Passkey added.', true);
        window.location.reload();
    }

    async function doAuthenticate(btn) {
        const options = toGetOptions(await postJson(btn.dataset.optionsUrl, {}));
        const cred = await navigator.credentials.get({ publicKey: options });
        if (!cred) throw new Error('No credential was returned.');
        const data = await postJson(btn.dataset.verifyUrl, serializeAssertion(cred));
        window.location.assign(data.redirect || '/dashboard');
    }

    function initPasskeys() {
        if (!window.PublicKeyCredential || !navigator.credentials) {
            document.querySelectorAll('[data-passkey-only]').forEach((el) => (el.hidden = true));
            return;
        }
        document.querySelectorAll('[data-passkey-only]').forEach((el) => (el.hidden = false));

        const bind = (selector, handler) => {
            document.querySelectorAll(selector).forEach((btn) => {
                btn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    btn.disabled = true;
                    try {
                        await handler(btn);
                    } catch (err) {
                        if (err.name !== 'NotAllowedError' && err.name !== 'AbortError') {
                            feedback(btn, err.message || 'Passkey operation failed.', false);
                        }
                    } finally {
                        btn.disabled = false;
                    }
                });
            });
        };

        bind('[data-passkey-register]', doRegister);
        bind('[data-passkey-login]', doAuthenticate);
        bind('[data-passkey-2fa]', doAuthenticate);
    }
})();
