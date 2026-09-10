<?php
/** Public-site end-of-body integrations: Tawk chat widget + WhatsApp button + cookie notice. */
$i = integrations();
$n = e_attr(nonce());
?>

<?= component('whatsapp-button') ?>

<?php if ($i->tawkEnabled()): ?>
    <script nonce="<?= $n ?>">
        (function () {
            var s1 = document.createElement('script'), s0 = document.getElementsByTagName('script')[0];
            s1.async = true;
            s1.src = <?= json_encode($i->tawkSrc()) ?>;
            s1.charset = 'UTF-8';
            s1.setAttribute('crossorigin', '*');
            s0.parentNode.insertBefore(s1, s0);
        })();
    </script>
<?php endif ?>

<?php if ($i->analyticsEnabled() && $i->analyticsRequiresConsent()): ?>
    <div id="cookie-notice" hidden
         class="fixed inset-x-0 bottom-0 z-50 border-t border-slate-200 bg-white p-3 text-sm shadow-lg sm:mx-auto sm:mb-3 sm:max-w-lg sm:rounded-xl sm:border">
        <p class="text-slate-600">We use cookies to understand how the site is used. You can accept or decline analytics cookies.</p>
        <div class="mt-2 flex gap-2">
            <button type="button" data-cookie="accepted" class="btn btn-primary btn-sm">Accept</button>
            <button type="button" data-cookie="declined" class="btn btn-secondary btn-sm">Decline</button>
        </div>
    </div>
    <script nonce="<?= $n ?>">
        (function () {
            var box = document.getElementById('cookie-notice');
            if (!box) return;
            var choice = null;
            try { choice = localStorage.getItem('cookie_consent'); } catch (e) {}
            if (!choice) box.hidden = false;
            box.querySelectorAll('[data-cookie]').forEach(function (b) {
                b.addEventListener('click', function () {
                    try { localStorage.setItem('cookie_consent', b.dataset.cookie); } catch (e) {}
                    box.hidden = true;
                    if (b.dataset.cookie === 'accepted' && window.gtag) {
                        gtag('consent', 'update', { analytics_storage: 'granted' });
                    }
                });
            });
        })();
    </script>
<?php endif ?>
