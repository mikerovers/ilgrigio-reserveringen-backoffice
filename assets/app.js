import './bootstrap.js';

/*
 * Il Grigio TicketHub - Main JavaScript Entry Point
 */

// Import minimal CSS styles
import './styles/app.css';

// Opens the modal_controller instance matching `data-modal-id-value` on any
// element with `[data-modal-open]`, even when the trigger lives outside the
// modal's own DOM subtree (e.g. the footer link opening the terms modal).
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-modal-open]');
    if (!trigger) {
        return;
    }

    window.dispatchEvent(
        new CustomEvent('modal:open', { detail: { id: trigger.dataset.modalOpen } }),
    );
});
