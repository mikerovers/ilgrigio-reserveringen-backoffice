import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";

// Connects to data-controller="scanner--kiosk"
//
// Turns the scan screen into a desk appliance:
//  - keeps the screen awake for the whole show (Screen Wake Lock API),
//  - gives the operator function-key shortcuts,
//  - in an installed window (standalone / window-controls-overlay) suppresses
//    the browser context menu and text selection on the scan panel.
const WCO_QUERY = "(display-mode: window-controls-overlay)";
const STANDALONE_QUERY = "(display-mode: standalone)";

export default class extends Controller {
    static values = { scanPath: String, scansPath: String, ordersPath: String };

    connect() {
        this.wakeLock = null;

        this.onKeydown = (event) => this.handleKeydown(event);
        this.onVisibility = () => this.updateWakeLock();
        this.onContextMenu = (event) => event.preventDefault();

        document.addEventListener("keydown", this.onKeydown);
        document.addEventListener("visibilitychange", this.onVisibility);

        // The element is data-turbo-permanent, so it survives page swaps: re-harden
        // the freshly rendered scan panel after each visit.
        this.onTurboLoad = () => this.isStandalone() && this.harden();
        document.addEventListener("turbo:load", this.onTurboLoad);

        if (this.isStandalone()) {
            this.harden();
        }

        this.updateWakeLock();
    }

    disconnect() {
        document.removeEventListener("keydown", this.onKeydown);
        document.removeEventListener("visibilitychange", this.onVisibility);
        document.removeEventListener("turbo:load", this.onTurboLoad);
        document.removeEventListener("contextmenu", this.onContextMenu);
        this.releaseWakeLock();
    }

    // --- kiosk hardening ----------------------------------------------------------

    isStandalone() {
        return (
            window.navigator.standalone === true ||
            window.matchMedia?.(WCO_QUERY).matches === true ||
            window.matchMedia?.(STANDALONE_QUERY).matches === true
        );
    }

    harden() {
        document.addEventListener("contextmenu", this.onContextMenu); // no-op when already added

        const panel = document.querySelector("[data-scanner-kiosk-panel]");
        if (panel) {
            panel.style.userSelect = "none";
            panel.style.webkitUserSelect = "none";
        }

        // The scan input itself keeps normal text behaviour for the operator
        const input = document.getElementById("scan-input");
        if (input) {
            input.style.userSelect = "text";
            input.style.webkitUserSelect = "text";
        }
    }

    // --- wake lock ----------------------------------------------------------------

    async updateWakeLock() {
        if (!("wakeLock" in navigator)) {
            return;
        }
        if (document.visibilityState !== "visible") {
            this.releaseWakeLock();
            return;
        }
        if (this.wakeLock !== null) {
            return;
        }

        try {
            this.wakeLock = await navigator.wakeLock.request("screen");
            this.wakeLock.addEventListener("release", () => (this.wakeLock = null));
        } catch {
            // Not fatal: the browser may refuse (low battery, OS policy)
            this.wakeLock = null;
        }
    }

    releaseWakeLock() {
        if (this.wakeLock === null) {
            return;
        }
        this.wakeLock.release().catch(() => {});
        this.wakeLock = null;
    }

    // --- function keys ------------------------------------------------------------

    handleKeydown(event) {
        if (event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        const action = {
            F1: () => this.navigate(this.scanPathValue),
            F2: () => this.navigate(this.scansPathValue),
            F3: () => this.navigate(this.ordersPathValue),
            F5: () => window.location.reload(),
            F11: () => this.toggleFullscreen(),
        }[event.key];

        if (action === undefined) {
            return;
        }
        event.preventDefault();
        action();
    }

    navigate(path) {
        if (!path) {
            return;
        }
        if (path !== window.location.pathname + window.location.search) {
            Turbo.visit(path);
        }
    }

    toggleFullscreen() {
        if (document.fullscreenElement) {
            document.exitFullscreen?.().catch(() => {});
            return;
        }
        document.documentElement.requestFullscreen?.().catch(() => {});
    }
}
