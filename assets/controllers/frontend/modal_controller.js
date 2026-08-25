import { Controller } from "@hotwired/stimulus";

// Connects to data-controller="frontend--modal"
// Opened from anywhere on the page (including outside this controller's
// element) by dispatching `window.dispatchEvent(new CustomEvent("modal:open", { detail: { id } }))`.
export default class extends Controller {
    static values = { id: String };

    connect() {
        this.previouslyFocused = null;
        this.onKeydown = this.onKeydown.bind(this);
        this.onOpenRequest = this.onOpenRequest.bind(this);
        window.addEventListener("modal:open", this.onOpenRequest);
    }

    disconnect() {
        window.removeEventListener("modal:open", this.onOpenRequest);
        document.removeEventListener("keydown", this.onKeydown);
    }

    onOpenRequest(event) {
        if (event.detail?.id === this.idValue) {
            this.open();
        }
    }

    open() {
        this.previouslyFocused = document.activeElement;
        this.element.classList.remove("hidden");
        document.body.style.overflow = "hidden";
        document.addEventListener("keydown", this.onKeydown);

        const focusTarget = this.element.querySelector(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
        );
        focusTarget?.focus();
    }

    close() {
        this.element.classList.add("hidden");
        document.body.style.overflow = "";
        document.removeEventListener("keydown", this.onKeydown);
        this.previouslyFocused?.focus();
    }

    onKeydown(event) {
        if (event.key === "Escape") {
            this.close();
        }
    }
}
