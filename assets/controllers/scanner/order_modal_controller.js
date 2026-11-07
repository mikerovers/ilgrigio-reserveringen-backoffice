import { Controller } from "@hotwired/stimulus";

// Connects to data-controller="scanner--order-modal"
// Popup with the order behind a scanned ticket. The order is fetched only when a row of
// "Laatste scans" is clicked. "Scan kaart" hands the code to the station controller.
function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
}

export default class extends Controller {
    static targets = ["dialog", "body"];
    static values = { url: String, canScan: { type: Boolean, default: true }, scanUrl: String };

    connect() {
        this.onOpen = (event) => this.open(event.detail.orderNumber);
        // A manual check-in keeps the popup open: reload the order so the row flips to "Gescand"
        this.onScanned = () => {
            if (this.dialogTarget.open) {
                this.load(true);
            }
        };
        // Starting to scan again (a printable key, i.e. the scanner typing) closes the popup
        this.onKeydown = (event) => {
            if (!this.dialogTarget.open || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }
            if (event.key.length === 1 && event.key !== " ") {
                this.close();
                document.getElementById("scan-input")?.focus({ preventScroll: true });
            }
        };
        window.addEventListener("scanner:open-order", this.onOpen);
        window.addEventListener("scanner:scanned", this.onScanned);
        document.addEventListener("keydown", this.onKeydown, true);
    }

    disconnect() {
        window.removeEventListener("scanner:open-order", this.onOpen);
        window.removeEventListener("scanner:scanned", this.onScanned);
        document.removeEventListener("keydown", this.onKeydown, true);
    }

    open(orderNumber) {
        this.orderNumber = orderNumber;
        if (!this.dialogTarget.open) {
            this.dialogTarget.showModal();
        }
        return this.load(false);
    }

    openFromRow(event) {
        this.open(event.currentTarget.dataset.orderNumber);
    }

    async load(silent) {
        const orderNumber = this.orderNumber;
        if (!silent) {
            this.bodyTarget.innerHTML = '<p class="px-8 py-10 text-gray-600">Order laden…</p>';
        }

        try {
            const response = await fetch(this.urlValue.replace("__ORDER__", encodeURIComponent(orderNumber)), {
                headers: { Accept: "application/json" },
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            this.render(await response.json());
        } catch {
            if (silent) {
                return;
            }
            this.bodyTarget.innerHTML = `
                <div class="px-8 py-10">
                    <p class="text-gray-700">Order kon niet worden geladen.</p>
                    <button type="button" data-action="scanner--order-modal#retry"
                            class="mt-4 border border-[#d6c4b1] px-5 py-2 font-semibold hover:bg-[#fffaf2]">Opnieuw proberen</button>
                </div>`;
        }
    }

    retry() {
        this.load(false);
    }

    render(order) {
        const rows = order.tickets.map((ticket) => {
            const action = ticket.scannable && this.canScanValue
                ? `<button type="button" data-action="scanner--order-modal#scan" data-code="${escapeHtml(ticket.code)}"
                           class="shrink-0 border border-[#d6c4b1] bg-white px-6 py-3 text-base font-semibold hover:bg-[#fffaf2] focus:outline-none focus:ring-2 focus:ring-[#c62828]">Scan kaart <span aria-hidden="true">→</span></button>`
                : ticket.scannable
                    ? `<span class="shrink-0 border border-[#d6c4b1] bg-[#fffaf2] px-3 py-1 text-xs font-bold uppercase tracking-[0.15em] text-gray-700">Niet gescand</span>`
                : ticket.status === "checked_in"
                    ? `<span class="shrink-0 border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.15em] text-emerald-900">Gescand ${escapeHtml(ticket.checkedInTime ?? "")}</span>`
                    : `<span class="shrink-0 border border-red-200 bg-red-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.15em] text-red-800">Vervallen</span>`;

            return `
                <li class="flex items-center justify-between gap-4 border-b border-[#eadfd1] py-4 last:border-b-0">
                    <div class="min-w-0">
                        <p class="text-xl font-semibold">${escapeHtml(ticket.typeName)}</p>
                        <p class="truncate font-mono text-sm text-gray-600">${escapeHtml(ticket.code)}</p>
                    </div>
                    ${action}
                </li>`;
        }).join("");

        this.bodyTarget.innerHTML = `
            <header class="flex items-start justify-between gap-4 border-b border-[#eadfd1] px-8 py-6">
                <div>
                    <h2 class="font-serif text-4xl">Order ${escapeHtml(order.orderNumber)}</h2>
                    <p class="mt-2 text-gray-600">${escapeHtml(order.customerName)} · ${order.total} ${order.total === 1 ? "kaart" : "kaarten"} · ${escapeHtml(order.eventDate)} · ${order.scanned} van ${order.total} gescand</p>
                </div>
                <button type="button" data-action="scanner--order-modal#close" aria-label="Sluiten" class="text-3xl leading-none text-gray-600 hover:text-black">×</button>
            </header>
            <ul class="px-8">${rows}</ul>
            <footer class="flex justify-end border-t border-[#eadfd1] px-8 py-5">
                <button type="button" data-action="scanner--order-modal#close"
                        class="border border-[#d6c4b1] bg-white px-8 py-3 font-semibold hover:bg-[#fffaf2] focus:outline-none focus:ring-2 focus:ring-[#c62828]">Sluiten</button>
            </footer>`;
    }

    async scan(event) {
        const code = event.currentTarget.dataset.code;
        event.currentTarget.disabled = true;

        // No scan station on this page (orders screen): check in directly.
        if (this.hasScanUrlValue && this.scanUrlValue !== "") {
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
            } catch {
                // fall through: reload shows the real state
            }
            this.dirty = true;
            await this.load(true);
            return;
        }

        window.dispatchEvent(new CustomEvent("scanner:submit", { detail: { code } }));
    }

    close() {
        if (this.dialogTarget.open) {
            this.dialogTarget.close();
        }
    }

    // Click on the backdrop (the dialog element itself) closes it
    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }

    // Always return the cursor to the scan input, also after Esc
    closed() {
        // The list behind the popup shows scan counters: refresh it after a check-in
        if (this.dirty) {
            window.location.reload();
            return;
        }
        document.getElementById("scan-input")?.focus({ preventScroll: true });
    }
}
