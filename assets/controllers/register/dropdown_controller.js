import { Controller } from '@hotwired/stimulus';

// Row action menu. The menu is positioned `fixed` from the trigger's rectangle,
// so the table's horizontal scroll container never clips it.
export default class extends Controller {
    static targets = ['menu', 'trigger'];

    toggle(event) {
        event.stopPropagation();
        if (this.menuTarget.classList.contains('hidden')) {
            this.open();
        } else {
            this.close();
        }
    }

    open() {
        document.dispatchEvent(new CustomEvent('register-dropdown:open', { detail: this }));
        const rect = this.triggerTarget.getBoundingClientRect();
        const menu = this.menuTarget;
        menu.classList.remove('hidden');
        menu.style.position = 'fixed';
        menu.style.right = `${Math.max(8, window.innerWidth - rect.right)}px`;
        const below = rect.bottom + 4;
        const fitsBelow = below + menu.offsetHeight <= window.innerHeight - 8;
        menu.style.top = `${fitsBelow ? below : Math.max(8, rect.top - menu.offsetHeight - 4)}px`;
        this.triggerTarget.setAttribute('aria-expanded', 'true');
    }

    close() {
        this.menuTarget.classList.add('hidden');
        this.triggerTarget.setAttribute('aria-expanded', 'false');
    }

    closeOthers(event) {
        if (event.detail !== this) {
            this.close();
        }
    }

    connect() {
        this.onOther = (event) => this.closeOthers(event);
        document.addEventListener('register-dropdown:open', this.onOther);
        window.addEventListener('scroll', this.closeBound = () => this.close(), true);
    }

    disconnect() {
        document.removeEventListener('register-dropdown:open', this.onOther);
        window.removeEventListener('scroll', this.closeBound, true);
    }
}
