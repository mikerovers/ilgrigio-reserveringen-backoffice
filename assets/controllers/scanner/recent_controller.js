import { Controller } from "@hotwired/stimulus";

// Connects to data-controller="scanner--recent"
// "Laatste scans": only scans made on THIS balie. They live in sessionStorage per show,
// so a page reload keeps the list, while scans by other balies (which arrive over Mercure
// as counter updates only) never show up here.
const MAX_STORED = 20;
const MAX_SHOWN = 5;

export default class extends Controller {
    static targets = ["list"];
    static values = { eventId: String, allUrl: String };

    connect() {
        this.onScanned = (event) => this.add(event.detail);
        window.addEventListener("scanner:scanned", this.onScanned);
        this.render();
    }

    disconnect() {
        window.removeEventListener("scanner:scanned", this.onScanned);
    }

    get storageKey() {
        return `scanner.recent.${this.eventIdValue}`;
    }

    load() {
        try {
            return JSON.parse(window.sessionStorage.getItem(this.storageKey) ?? "[]");
        } catch {
            return [];
        }
    }

    add(entry) {
        const entries = [entry, ...this.load()].slice(0, MAX_STORED);
        try {
            window.sessionStorage.setItem(this.storageKey, JSON.stringify(entries));
        } catch {
            // storage unavailable (private mode): the list simply starts empty after a reload
        }
        this.render(entries);
    }

    render(entries = this.load()) {
        const list = this.listTarget;
        list.replaceChildren();

        if (entries.length === 0) {
            const empty = document.createElement("li");
            empty.className = "py-6 text-center text-sm text-gray-500";
            empty.textContent = "Nog geen scans bij deze voorstelling. De eerste scan verschijnt hier.";
            list.append(empty);
            return;
        }

        entries.slice(0, MAX_SHOWN).forEach((entry) => list.append(this.row(entry)));
    }

    row(entry) {
        const li = document.createElement("li");
        // Rows of a known order open the order popup; unknown codes have no order
        const clickable = Boolean(entry.orderNumber);
        const holder = clickable ? document.createElement("button") : li;
        if (clickable) {
            holder.type = "button";
            holder.setAttribute("aria-label", `Order ${entry.orderNumber} bekijken`);
            holder.addEventListener("click", () => {
                window.dispatchEvent(new CustomEvent("scanner:open-order", { detail: { orderNumber: entry.orderNumber } }));
            });
            li.className = "border-b border-transparent";
        }
        holder.className = `flex w-full items-center gap-3 px-5 py-3 text-left${clickable ? " hover:bg-[#fffaf2] focus:outline-none focus:ring-2 focus:ring-[#c62828]" : ""}`;

        const time = document.createElement("span");
        time.className = "w-12 shrink-0 font-mono text-base text-gray-700";
        time.textContent = entry.time;

        const label = document.createElement("span");
        label.className = "min-w-0 flex-1 truncate text-base text-gray-600";
        label.textContent = entry.label;

        const badge = document.createElement("span");
        badge.className = entry.ok
            ? "shrink-0 border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.15em] text-emerald-900"
            : "shrink-0 border border-red-200 bg-red-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.15em] text-red-800";
        badge.textContent = entry.ok ? "Toegelaten" : "Geweigerd";

        holder.append(time, label, badge);
        if (clickable) {
            li.append(holder);
        }
        return li;
    }
}
