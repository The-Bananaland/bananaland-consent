<?php
/**
 * Floating button that reopens preferences.
 *
 * @version 0.1.0
 * @var \Bananaland\Consent\Templates\View $consent
 */

defined('ABSPATH') || exit;
?>
<button type="button" class="blc-float blc-float--<?php echo esc_attr($consent->floating_position()); ?>" data-blc="floating-button" data-blc-action="open-preferences" hidden>
    <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-4-4 4 4 0 0 1-4-4 2 2 0 0 0-2-2Zm-3.5 7a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm6 5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm-7 2a1 1 0 1 1 0 2 1 1 0 0 1 0-2Z"/></svg>
    <span class="blc-sr-only"><?php echo esc_html($consent->text('links', 'preferences')); ?></span>
</button>
