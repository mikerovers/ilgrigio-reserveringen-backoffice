import { Controller } from "@hotwired/stimulus";

// Connects to data-controller="scanner--station"
//
// The scan input stays focused so a USB keyboard-wedge scanner (which "types" the code
// and presses Enter) always lands in it. Browsers cannot see whether such a scanner is
// attached (it is a plain HID keyboard), so we recognise it by behaviour instead: a
// burst of fast keystrokes ending in Enter.
const SCANNER_MAX_KEY_INTERVAL_MS = 50;
const SCANNER_MIN_LENGTH = 6;
const AUTO_RESET_MS = 4000;
const IDLE_PLACEHOLDER = "Wacht op scan…";
// Tailwind arbitrary variant: applies on short browser windows
const SHORT = "[@media(max-height:820px)]";

const timeFormat = new Intl.DateTimeFormat("nl-NL", {
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "Europe/Amsterdam",
});

const TINT = {
    ok: { band: "bg-emerald-50 border-emerald-500", icon: "bg-emerald-500", kicker: "text-emerald-700" },
    warn: { band: "bg-amber-50 border-amber-500", icon: "bg-amber-500", kicker: "text-amber-700" },
    bad: { band: "bg-red-50 border-red-500", icon: "bg-red-500", kicker: "text-red-600" },
};

const ICONS = {
    ok: '<path d="M5 12.5l4.5 4.5L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
    warn: '<path d="M12 7v5l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="2"/>',
    bad: '<path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
};

function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
}

export default class extends Controller {
    static targets = ["input", "idle", "illustration", "result", "ready", "readyDot", "lastScan", "announcer", "keyboardToggle"];
    static values = { scanUrl: String, operator: String };

    connect() {
        this.queue = [];
        this.processing = false;
        this.keyTimes = [];
        this.scannerSeen = false;
        this.onDocumentKeydown = (event) => this.handleDocumentKeydown(event);
        document.addEventListener("keydown", this.onDocumentKeydown);
        // "Scan kaart" in the order popup hands a ticket code over to the normal scan flow
        this.onSubmitRequest = (event) => {
            this.submit(event.detail.code);
            this.focusInput();
        };
        window.addEventListener("scanner:submit", this.onSubmitRequest);
        // Do not leave the page while a scan is still being checked: its result would be lost
        this.onBeforeVisit = (event) => {
            if (this.processing || this.queue.length > 0) {
                event.preventDefault();
            }
        };
        document.addEventListener("turbo:before-visit", this.onBeforeVisit);
        // Touch devices (phones/tablets): the code comes from an external scanner that types
        // like a keyboard, so keep the on-screen keyboard hidden. "Toetsenbord" brings it back
        // for typing a code by hand.
        this.touchDevice = window.matchMedia("(pointer: coarse)").matches;
        if (this.touchDevice) {
            this.inputTarget.setAttribute("inputmode", "none");
            if (this.hasKeyboardToggleTarget) {
                this.keyboardToggleTarget.hidden = false;
            }
        }
        // Coming back to the app (lock screen, app switcher): the input lost focus
        this.onVisibilityChange = () => {
            if (document.visibilityState === "visible") {
                this.focusInput();
            }
        };
        document.addEventListener("visibilitychange", this.onVisibilityChange);
        this.focusInput();
        this.renderReady();
    }

    disconnect() {
        document.removeEventListener("visibilitychange", this.onVisibilityChange);
        document.removeEventListener("keydown", this.onDocumentKeydown);
        window.removeEventListener("scanner:submit", this.onSubmitRequest);
        document.removeEventListener("turbo:before-visit", this.onBeforeVisit);
        window.clearTimeout(this.resetTimer);
    }

    // --- keyboard -----------------------------------------------------------------

    onInputKeydown(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            const code = this.inputTarget.value.trim();
            if (code === "") {
                // Enter on an empty field just dismisses the result
                this.next();
                return;
            }
            this.submit(code);
            return;
        }
        if (event.key.length === 1) {
            this.keyTimes.push(performance.now());
        }
    }

    handleDocumentKeydown(event) {
        if (event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        // The scan input must always receive the scanner's keystrokes, also while a
        // result is showing: Enter or any printable key puts the cursor back in it
        // (the key itself is then typed into the freshly focused input).
        if (document.activeElement === this.inputTarget || this.isFormControl(document.activeElement)) {
            return;
        }
        if (event.key === "Enter") {
            event.preventDefault();
            this.focusInput();
        } else if (event.key.length === 1) {
            this.focusInput();
        }
    }

    isFormControl(element) {
        return element instanceof HTMLElement && ["INPUT", "SELECT", "TEXTAREA", "BUTTON", "A"].includes(element.tagName);
    }

    onFocus() {
        this.renderReady();
    }

    onBlur() {
        this.renderReady();
    }

    // A tap on the scan panel (outside buttons) must not leave the input without focus,
    // otherwise the next scanned code is lost until someone presses Enter.
    refocus(event) {
        if (event.target instanceof Element && event.target.closest("button, a, select")) {
            return;
        }
        this.focusInput();
    }

    toggleKeyboard() {
        const hidden = this.inputTarget.getAttribute("inputmode") === "none";
        this.inputTarget.setAttribute("inputmode", hidden ? "text" : "none");
        // Re-focus so the browser applies the new inputmode (shows/hides the keyboard)
        this.inputTarget.blur();
        this.focusInput();
    }

    focusInput() {
        if (!this.hasInputTarget) {
            return;
        }
        this.inputTarget.focus({ preventScroll: true });
    }

    // --- scanning -----------------------------------------------------------------

    looksLikeScanner() {
        const times = this.keyTimes;
        if (times.length < SCANNER_MIN_LENGTH) {
            return false;
        }
        const span = times[times.length - 1] - times[0];
        return span / (times.length - 1) <= SCANNER_MAX_KEY_INTERVAL_MS;
    }

    // A scan is queued and handled in order, so a second code scanned while the first is
    // still being checked is never lost and results cannot arrive out of order.
    submit(raw) {
        const code = String(raw ?? "").trim();
        if (code === "") {
            return;
        }
        if (this.looksLikeScanner()) {
            this.scannerSeen = true;
        }
        this.keyTimes = [];
        this.inputTarget.value = "";
        this.queue.push(code);
        this.processQueue();
    }

    async processQueue() {
        if (this.processing) {
            return;
        }
        this.processing = true;
        try {
            while (this.queue.length > 0) {
                await this.check(this.queue.shift());
            }
        } finally {
            this.processing = false;
            this.inputTarget.placeholder = IDLE_PLACEHOLDER;
        }
    }

    async check(code) {
        this.inputTarget.placeholder = this.queue.length > 0 ? `Controleren… (+${this.queue.length})` : "Controleren…";

        let data = null;
        try {
            const response = await fetch(this.scanUrlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({ ticketCode: code, scanId: crypto.randomUUID() }),
            });
            if (response.status === 401 || response.status === 403) {
                window.location.reload();
                return;
            }
            if (response.status === 422) {
                data = { result: "NOT_FOUND", ticketCode: code };
            } else if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            } else {
                data = await response.json();
            }
        } catch {
            this.showResult(this.describe({ result: "ERROR" }, code));
            return;
        }

        const now = timeFormat.format(new Date());
        const view = this.describe(data, code, now);
        this.lastScanTarget.textContent = now;
        if (data.stats) {
            window.dispatchEvent(new CustomEvent("scanner:stats", { detail: data.stats }));
        }
        window.dispatchEvent(new CustomEvent("scanner:scanned", {
            detail: { time: now, label: view.recentLabel, ok: view.tone === "ok", orderNumber: data.orderNumber ?? null },
        }));
        this.showResult(view);
    }

    // --- result panel -------------------------------------------------------------

    describe(data, code, now = timeFormat.format(new Date())) {
        const card = data.ticketTypeName ?? "Geen geldige QR-code";
        const scanned = ["Gescande code", code, { wide: true, mono: true }];
        const scanBy = `${now} · ${this.operatorValue}`;
        const order = data.orderNumber ?? "Niet gevonden";
        const recentLabel = data.ticketTypeName ? `${data.ticketTypeName} · ${data.orderNumber}` : `Onbekende code · ${code}`;

        switch (data.result) {
            case "ADMITTED":
                return {
                    tone: "ok", recentLabel,
                    kicker: "Toegelaten · Geldige kaart", title: "Toegang verleend",
                    fields: [["Kaart", card], ["Scan", scanBy], ["Order", order], ["Actie", "Laat binnen"], scanned],
                    note: "Kaart geregistreerd. De bezoeker kan doorlopen.",
                    autoReset: true,
                };
            case "ALREADY_CHECKED_IN":
                return {
                    tone: "warn", recentLabel,
                    kicker: "Geweigerd · al gescand", title: "Kaart al gebruikt",
                    fields: [
                        ["Kaart", card],
                        ["Eerste scan", [data.checkedInTime, data.checkedInBy].filter(Boolean).join(" · ") || "—"],
                        ["Order", order],
                        ["Actie", "Controleer de bevestiging"],
                        scanned,
                    ],
                    note: "Deze kaart is al eerder gescand. Controleer de order voordat u toegang geeft.",
                };
            case "VOID":
                return {
                    tone: "bad", recentLabel,
                    kicker: "Geweigerd · kaart vervallen", title: "Kaart niet geldig",
                    fields: [["Kaart", card], ["Scan", scanBy], ["Order", order], ["Actie", "Controleer de bevestiging"], scanned],
                    note: "Deze kaart is geannuleerd of teruggestort. Vraag om de orderbevestiging.",
                };
            case "ERROR":
                return {
                    tone: "bad", recentLabel: "Niet verwerkt",
                    kicker: "Fout · geen verbinding", title: "Scan niet verwerkt",
                    fields: [["Kaart", "Niet gecontroleerd"], ["Scan", scanBy], ["Order", "Onbekend"], ["Actie", "Scan opnieuw"], scanned],
                    note: "De scan is niet gecontroleerd. Controleer de verbinding en scan de kaart opnieuw.",
                };
            default:
                return {
                    tone: "bad", recentLabel,
                    kicker: "Geweigerd · onbekende code", title: "Ongeldige kaart",
                    fields: [["Kaart", card], ["Scan", scanBy], ["Order", "Niet gevonden"], ["Actie", "Controleer de bevestiging"], scanned],
                    note: "Deze code hoort niet bij een kaart van Il Grigio voor deze voorstelling. Vraag om de orderbevestiging.",
                };
        }
    }

    showResult(view) {
        const tint = TINT[view.tone];
        const fields = view.fields
            .map(([label, value, opts = {}]) => `
                <div class="${opts.wide ? "col-span-2" : ""}">
                    <dt class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-600">${escapeHtml(label)}</dt>
                    <dd class="mt-0.5 text-lg ${SHORT}:text-base ${opts.mono ? "break-all font-mono" : ""}">${escapeHtml(value)}</dd>
                </div>`)
            .join("");

        this.resultTarget.innerHTML = `
            <div class="border border-[#eadfd1] bg-white text-left text-[#34272a]">
                <div class="flex items-center gap-4 border-b-2 px-5 py-3 ${SHORT}:py-2 ${tint.band}">
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center text-white ${SHORT}:h-10 ${SHORT}:w-10 ${tint.icon}" aria-hidden="true">
                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none">${ICONS[view.tone]}</svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] ${tint.kicker}">${escapeHtml(view.kicker)}</p>
                        <h3 class="font-serif text-3xl ${SHORT}:text-2xl">${escapeHtml(view.title)}</h3>
                    </div>
                </div>
                <div class="px-5 py-4 ${SHORT}:py-3">
                    <dl class="grid gap-x-8 gap-y-3 ${SHORT}:gap-y-2 grid-cols-2 md:grid-cols-3">${fields}</dl>
                    <hr class="my-3 border-[#eadfd1] ${SHORT}:hidden">
                    <p class="text-base text-gray-600 ${SHORT}:hidden">${escapeHtml(view.note)}</p>
                </div>
            </div>
            <button type="button" data-action="scanner--station#next"
                    class="block w-full bg-[#c8372f] px-6 py-3 ${SHORT}:py-2 max-md:py-4 text-base font-semibold text-white hover:bg-[#a91f24] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#c8372f]">
                Volgende kaart <span aria-hidden="true">→</span>
            </button>`;

        this.resultTarget.hidden = false;
        this.setCompact(true);
        this.announcerTarget.textContent = `${view.title}. ${view.kicker}.`;
        // Haptic feedback on phones (Android; ignored where unsupported): one short pulse
        // for admitted, a long double pulse for any refusal.
        if (this.touchDevice) {
            navigator.vibrate?.(view.tone === "ok" ? 80 : [250, 100, 250]);
        }
        this.renderReady();

        window.clearTimeout(this.resetTimer);
        if (view.autoReset) {
            this.resetTimer = window.setTimeout(() => this.next(), AUTO_RESET_MS);
        }
    }

    get resultVisible() {
        return this.hasResultTarget && !this.resultTarget.hidden;
    }

    next() {
        window.clearTimeout(this.resetTimer);
        this.resultTarget.hidden = true;
        this.resultTarget.replaceChildren();
        this.setCompact(false);
        this.focusInput();
        this.renderReady();
    }

    // The result takes the place of the illustration inside the same panel, so the
    // input and everything around it stay exactly where they are.
    setCompact(showResult) {
        this.illustrationTarget.hidden = showResult;
        this.illustrationTarget.classList.toggle("hidden", showResult);
    }

    // --- status badge -------------------------------------------------------------

    renderReady() {
        if (!this.hasReadyTarget) {
            return;
        }
        const focused = document.activeElement === this.inputTarget;
        let label;
        let active = true;
        if (!focused) {
            label = "Druk op Enter om te scannen";
            active = false;
        } else {
            label = this.scannerSeen ? "Scanner gedetecteerd" : "Scanner gereed";
        }
        this.readyTarget.textContent = label;
        this.readyDotTarget.classList.toggle("bg-white", active);
        this.readyDotTarget.classList.toggle("bg-transparent", !active);
    }
}
