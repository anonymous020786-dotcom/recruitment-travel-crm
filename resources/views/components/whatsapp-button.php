<?php
/**
 * Floating WhatsApp button. Renders nothing when the integration is unconfigured.
 * component('whatsapp-button', ['text' => 'Optional prefilled message'])
 */
$i = integrations();
if (!$i->whatsappEnabled()) {
    return;
}
$href = $i->whatsappLink($text ?? null);
?>
<a href="<?= e_attr($href) ?>" target="_blank" rel="noopener nofollow"
   class="fixed bottom-4 right-4 z-40 inline-flex items-center gap-2 rounded-full bg-[#25D366] px-4 py-3 text-sm font-semibold text-white shadow-lg hover:brightness-95"
   aria-label="Chat on WhatsApp">
    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 2.1.55 4.06 1.6 5.83L2 22l4.4-1.15a9.9 9.9 0 0 0 5.64 1.72h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm5.8 14.2c-.25.7-1.45 1.34-2 1.42-.53.08-1.2.11-1.94-.12-.45-.14-1.02-.33-1.76-.64-3.1-1.34-5.12-4.46-5.28-4.66-.15-.2-1.26-1.67-1.26-3.19 0-1.52.8-2.27 1.08-2.58.28-.31.61-.39.81-.39.2 0 .41 0 .58.01.19.01.44-.07.68.52.25.6.86 2.07.94 2.22.08.15.13.33.02.53-.1.2-.16.32-.31.5-.15.17-.32.39-.46.52-.15.15-.31.31-.13.61.18.3.8 1.32 1.72 2.14 1.18 1.05 2.17 1.38 2.48 1.53.31.15.49.13.67-.08.18-.2.77-.9.98-1.21.2-.31.41-.26.68-.15.28.1 1.77.83 2.07.98.31.15.51.23.59.36.08.13.08.75-.17 1.46Z"/>
    </svg>
    <span class="hidden sm:inline">WhatsApp</span>
</a>
