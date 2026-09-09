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
    });
})();
