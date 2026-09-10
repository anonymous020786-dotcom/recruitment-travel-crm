<?php
/**
 * Cloudflare Turnstile widget. Renders nothing (and adds no requirement) when
 * unconfigured. Place inside a <form>; the token posts as `cf-turnstile-response`.
 */
$siteKey = (string) config('integrations.turnstile.site_key', '');
if ($siteKey === '') {
    return;
}
?>
<div class="cf-turnstile my-3" data-sitekey="<?= e_attr($siteKey) ?>" data-theme="light" data-size="flexible"></div>
