<?php
/**
 * Public-site <head> integrations: GA4 (consent-gated) and the Turnstile loader.
 * Nonce is required by the public CSP for these inline snippets.
 */
$i = integrations();
$n = e_attr(nonce());
?>
<?php if ($i->analyticsEnabled()): $id = e_attr($i->ga4Id()); ?>
    <script nonce="<?= $n ?>" src="https://www.googletagmanager.com/gtag/js?id=<?= $id ?>" async></script>
    <script nonce="<?= $n ?>">
        window.dataLayer = window.dataLayer || [];
        function gtag(){ dataLayer.push(arguments); }
        gtag('js', new Date());
        <?php if ($i->analyticsRequiresConsent()): ?>
        gtag('consent', 'default', { ad_storage: 'denied', analytics_storage: 'denied' });
        try {
            if (localStorage.getItem('cookie_consent') === 'accepted') {
                gtag('consent', 'update', { analytics_storage: 'granted' });
            }
        } catch (e) {}
        <?php endif ?>
        gtag('config', '<?= $id ?>', { anonymize_ip: true });
    </script>
<?php endif ?>

<?php if (config('integrations.turnstile.site_key', '') !== ''): ?>
    <script nonce="<?= $n ?>" src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif ?>
