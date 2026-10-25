import { Controller } from "@hotwired/stimulus";
import { getComponent } from "@symfony/ux-live-component";

// Delay before recalculating coupon totals after quantity changes.
const TOTALS_REFRESH_DELAY_MS = 300;

// Connects to data-controller="frontend--ticket-selection"
//
// Steppers, the item list and the no-coupon totals run locally. Validation and
// coupon handling run on the server through the TicketSelection Live Component;
// its JSON state (data-ticket-selection-state) drives row errors and totals.
export default class extends Controller {
    static targets = [
        "quantity",
        "orderItems",
        "totalTickets",
        "totalPrice",
        "subtotalPrice",
        "proceedButton",
        "errorContainer",
        "errorMessage",
        "couponInput",
        "appliedCouponField",
        "discountRow",
        "discountAmount",
        "couponDescription",
        "discountAmountField",
        "maxTicketsWarning",
        "taxAmount",
    ];
    static values = {
        ticketTypes: Array,
        eventPermalink: String,
        maxTicketsPerOrder: Number,
        taxRate: Number,
        sharedStock: Number,
    };

    connect() {
        this.requestSeq = 0;
        this.refreshTimer = null;
        this.transientMessage = null;
        // Last totals returned by the server; null until the first response arrives.
        this.serverTotals = null;

        const liveRoot = this.element.querySelector("[data-live-name-value]");
        this.componentPromise = liveRoot ? getComponent(liveRoot) : Promise.reject(new Error("Live component missing"));
        // Avoid unhandled rejection if the component is missing; callers handle the error.
        this.componentPromise.catch(() => {});

        this.updateOrderSummary();
        // Totals always come from the server, so fetch them for the current selection.
        this.refreshTotals();
    }

    disconnect() {
        clearTimeout(this.refreshTimer);
    }

    // ---- Steppers -------------------------------------------------------

    decreaseQuantity(event) {
        const input = this.inputFor(event);

        if (parseInt(input.value) > 0) {
            input.value = parseInt(input.value) - 1;
            this.selectionChanged();
        }
    }

    increaseQuantity(event) {
        const input = this.inputFor(event);
        const currentTotal = this.calculateTotalTickets();

        if (currentTotal + 1 > this.maxTicketsPerOrderValue) {
            this.showTransientMessage(
                `Je kunt maximaal ${this.maxTicketsPerOrderValue} tickets per bestelling kiezen.`,
            );
            return;
        }

        if (this.sharedStockValue && currentTotal + 1 > this.sharedStockValue) {
            this.showTransientMessage(
                `Er zijn nog maar ${this.sharedStockValue} kaarten beschikbaar voor deze show.`,
            );
            return;
        }

        input.value = parseInt(input.value) + 1;
        this.selectionChanged();
    }

    quantityChanged(event) {
        const input = event.target;
        const value = Math.max(0, parseInt(input.value) || 0);
        input.value = value;

        // Largest value this row may take given the other rows.
        const others = this.calculateTotalTickets() - value;
        const maxForRow = Math.max(0, this.maxTicketsPerOrderValue - others);
        const stockForRow = this.sharedStockValue
            ? Math.max(0, this.sharedStockValue - others)
            : Infinity;
        const allowed = Math.min(maxForRow, stockForRow);

        let message = null;
        if (value > allowed) {
            input.value = allowed;
            message =
                allowed === maxForRow
                    ? `Je kunt maximaal ${this.maxTicketsPerOrderValue} tickets per bestelling kiezen.`
                    : `Er zijn nog maar ${this.sharedStockValue} kaarten beschikbaar voor deze show.`;
        }

        this.selectionChanged(message);
    }

    calculateTotalTickets() {
        let total = 0;
        this.quantityTargets.forEach((input) => {
            if (!input.disabled) {
                total += parseInt(input.value) || 0;
            }
        });
        return total;
    }

    // Any change to the selection clears stale errors and refreshes local totals.
    selectionChanged(transientMessage = null) {
        this.transientMessage = transientMessage;
        this.clearRowErrors();
        this.hideError();
        this.updateOrderSummary();
        this.scheduleTotalsRefresh();
    }

    // ---- Submit ---------------------------------------------------------

    // Row errors come from the server on every change (see refreshTotals), so submit only
    // needs to stop on what is already shown. The server re-validates on the POST regardless.
    async validateForm(event) {
        event.preventDefault();

        if (this.submitting) {
            return;
        }

        this.hideError();

        if (this.calculateTotalTickets() === 0) {
            this.showError("Selecteer minimaal één ticket om door te gaan.");
            return;
        }

        this.submitting = true;
        try {
            // Run a pending debounced refresh now, then wait for the latest one, so the
            // row errors checked below describe the selection the customer is submitting.
            if (this.refreshTimer !== null) {
                clearTimeout(this.refreshTimer);
                this.refreshTimer = null;
                this.refreshTotals();
            }
            await this.refreshPromise;

            const firstVisibleRowError = this.element.querySelector("[data-row-error]:not([hidden])");
            if (firstVisibleRowError) {
                const input = this.quantityTargets.find(
                    (el) => el.dataset.ticketId === firstVisibleRowError.dataset.rowError,
                );
                this.showError("Pas het aantal aan voordat je doorgaat.");
                input?.scrollIntoView({ behavior: "smooth", block: "center" });
                return;
            }

            this.element.submit();
        } finally {
            this.submitting = false;
        }
    }

    // ---- Coupons --------------------------------------------------------

    // Do not toggle `disabled` on the apply button: it lives inside the Live root, and
    // Live re-applies DOM changes made during a request onto the re-rendered element,
    // which left the button disabled after an invalid code. Track the pending state here.
    async applyCoupon() {
        if (this.couponPending) {
            return;
        }
        this.couponPending = true;

        try {
            const state = await this.callLive("applyCoupon", {
                code: this.couponInputTarget.value.trim(),
                tickets: this.selectionPayload(),
            });
            this.applyServerState(state);
        } catch (error) {
            console.error("Error applying coupon:", error);
            this.showError("Kortingscode kon niet worden gecontroleerd. Probeer het opnieuw.", {
                scroll: false,
            });
        } finally {
            this.couponPending = false;
        }
    }

    async removeCoupon() {
        try {
            const state = await this.callLive("removeCoupon", {
                tickets: this.selectionPayload(),
            });
            this.applyServerState(state);
        } catch (error) {
            console.error("Error removing coupon:", error);
        }
    }

    // Every selection change re-prices on the server; debounced to avoid a request per click.
    scheduleTotalsRefresh() {
        clearTimeout(this.refreshTimer);
        this.refreshTimer = setTimeout(() => {
            this.refreshTimer = null;
            this.refreshTotals();
        }, TOTALS_REFRESH_DELAY_MS);
    }

    // Starts a refresh and remembers it, so submit can wait for the latest one.
    refreshTotals() {
        const seq = ++this.requestSeq;
        this.refreshPromise = this.performRefresh(seq);
        return this.refreshPromise;
    }

    async performRefresh(seq) {
        this.setTotalsBusy(true);

        try {
            const state = await this.callLive("refreshTotals", {
                tickets: this.selectionPayload(),
            });

            // Only the latest response is applied; earlier ones may describe an old selection.
            if (seq === this.requestSeq) {
                this.applyServerState(state);
                // Row errors (sold-out tickets, per-row stock) come back with every refresh.
                // Form-level messages are not shown on change, so an emptied selection is not flagged.
                this.clearRowErrors();
                this.applyRowErrors(state.rowErrors, { scroll: false });
            }
        } catch (error) {
            console.error("Error refreshing totals:", error);
        } finally {
            if (seq === this.requestSeq) {
                this.setTotalsBusy(false);
            }
        }
    }

    // Dims the totals while a refresh is in flight, so a lagging figure is not read as final.
    setTotalsBusy(busy) {
        [this.subtotalPriceTarget, this.taxAmountTarget, this.totalPriceTarget, this.discountAmountTarget].forEach(
            (el) => {
                el.classList.toggle("opacity-50", busy);
                el.setAttribute("aria-busy", String(busy));
            },
        );
    }

    // ---- Live plumbing --------------------------------------------------

    async callLive(name, args) {
        const component = await this.componentPromise;
        const response = await component.action(name, args);
        const html = await response.getBody();

        const doc = new DOMParser().parseFromString(html, "text/html");
        const stateNode = doc.querySelector("[data-ticket-selection-state]");
        if (!stateNode) {
            throw new Error(`Live action "${name}" returned no state`);
        }

        return JSON.parse(stateNode.dataset.ticketSelectionState);
    }

    // Quantities for every enabled row, including zeros, so the server sees the full selection.
    selectionPayload() {
        const tickets = {};
        this.quantityTargets.forEach((input) => {
            if (!input.disabled) {
                tickets[input.dataset.ticketId] = input.value || "0";
            }
        });
        return JSON.stringify(tickets);
    }

    applyServerState(state) {
        this.serverTotals = state.totals;
        this.serverCouponCode = state.couponCode ?? null;
        this.updateOrderSummary();
    }

    // ---- Feedback -------------------------------------------------------

    applyRowErrors(rowErrors, { scroll = true } = {}) {
        let first = null;

        Object.entries(rowErrors).forEach(([ticketTypeId, message]) => {
            const input = this.quantityTargets.find((el) => el.dataset.ticketId === ticketTypeId);
            const messageEl = this.element.querySelector(`[data-row-error="${ticketTypeId}"]`);

            if (messageEl) {
                messageEl.textContent = message;
                messageEl.hidden = false;
            }
            if (input) {
                input.setAttribute("aria-invalid", "true");
                first = first ?? input;
            }
        });

        if (first && scroll) {
            first.scrollIntoView({ behavior: "smooth", block: "center" });
        }
    }

    clearRowErrors() {
        this.element.querySelectorAll("[data-row-error]").forEach((el) => {
            el.textContent = "";
            el.hidden = true;
        });
        this.quantityTargets.forEach((input) => input.removeAttribute("aria-invalid"));
    }

    showError(message, { scroll = true } = {}) {
        this.errorMessageTarget.textContent = message;
        this.errorContainerTarget.classList.remove("hidden");

        if (scroll) {
            this.errorContainerTarget.scrollIntoView({ behavior: "smooth", block: "center" });
        }
    }

    hideError() {
        this.errorContainerTarget.classList.add("hidden");
    }

    showTransientMessage(message) {
        this.transientMessage = message;
        this.renderWarning(0);
    }

    // ---- Summary --------------------------------------------------------

    inputFor(event) {
        return this.element.querySelector(`#quantity-${event.currentTarget.dataset.ticketId}`);
    }

    updateOrderSummary() {
        let totalTickets = 0;
        let orderHTML = "";

        this.quantityTargets.forEach((input) => {
            if (input.disabled) {
                return;
            }

            const quantity = parseInt(input.value);
            if (quantity > 0) {
                const ticketId = input.dataset.ticketId;
                const price = parseFloat(input.dataset.price);
                const ticketType = this.ticketTypesValue.find((t) => t.id == ticketId);

                if (ticketType) {
                    totalTickets += quantity;

                    orderHTML += `
                        <div class="flex justify-between items-center">
                            <div>
                                <span class="font-medium">${quantity}x ${ticketType.name}</span>
                                <div class="text-sm text-gray-600">€${price.toFixed(2)} per ticket</div>
                            </div>
                            <span class="font-semibold">€${(quantity * price).toFixed(2)}</span>
                        </div>
                    `;
                }
            }
        });

        if (totalTickets === 0) {
            orderHTML = '<p class="text-gray-500 text-center">Geen tickets geselecteerd</p>';
        }

        // Totals are never computed in the browser: they show the server's figures for
        // the last priced selection. Until the first response arrives they stay as rendered.
        this.orderItemsTarget.innerHTML = orderHTML;
        this.totalTicketsTarget.textContent = totalTickets;

        if (this.serverTotals) {
            const { subtotalNet, tax, totalGross, discountGross } = this.serverTotals;

            this.subtotalPriceTarget.textContent = `€${subtotalNet.toFixed(2)}`;
            this.taxAmountTarget.textContent = `€${tax.toFixed(2)}`;
            this.totalPriceTarget.textContent = `€${totalGross.toFixed(2)}`;

            if (discountGross > 0 && this.serverCouponCode) {
                this.discountAmountTarget.textContent = `-€${discountGross.toFixed(2)}`;
                this.couponDescriptionTarget.textContent = this.serverCouponCode;
                this.discountRowTarget.classList.remove("hidden");
            } else {
                this.discountRowTarget.classList.add("hidden");
            }
            this.discountAmountFieldTarget.value = discountGross.toFixed(2);
        }

        this.proceedButtonTarget.disabled = totalTickets === 0;
        this.renderWarning(totalTickets);
    }

    // Warning box near the totals. Shows a transient message (limit reached) if set,
    // otherwise the standing stock/limit notice.
    renderWarning(totalTickets) {
        if (!this.hasMaxTicketsWarningTarget) {
            return;
        }

        const warning = this.maxTicketsWarningTarget;
        const tone = {
            error: ["text-red-600", "bg-red-50", "border-red-200"],
            warning: ["text-amber-600", "bg-amber-50", "border-amber-200"],
        };
        const allToneClasses = [
            "text-gray-500",
            "bg-gray-50",
            ...tone.error,
            ...tone.warning,
        ];
        warning.classList.remove(...allToneClasses);

        if (this.transientMessage) {
            warning.innerHTML = `<i class="fas fa-info-circle mr-1"></i> ${this.transientMessage}`;
            warning.classList.add(...tone.error);
            warning.classList.remove("hidden");
            return;
        }

        const remaining = this.sharedStockValue ? this.sharedStockValue - totalTickets : null;
        if (this.sharedStockValue && totalTickets >= this.sharedStockValue) {
            warning.innerHTML = `<i class="fas fa-info-circle mr-1"></i> Er zijn nog maar ${this.sharedStockValue} kaarten beschikbaar voor deze show.`;
            warning.classList.add(...tone.error);
            warning.classList.remove("hidden");
        } else if (this.sharedStockValue && remaining <= 5 && totalTickets > 0) {
            warning.innerHTML = `<i class="fas fa-info-circle mr-1"></i> Nog ${remaining} kaarten beschikbaar voor deze show.`;
            warning.classList.add(...tone.warning);
            warning.classList.remove("hidden");
        } else if (totalTickets >= this.maxTicketsPerOrderValue - 5) {
            warning.innerHTML = `<i class="fas fa-info-circle mr-1"></i> Maximum ${this.maxTicketsPerOrderValue} tickets per bestelling. Groepen vanaf ${this.maxTicketsPerOrderValue} personen kunnen in aanmerking komen voor korting mits zij van tevoren gereserveerd hebben. Voor informatie over groepskortingen ontvangen wij graag een e-mail (info@ilgrigio.nl).`;
            warning.classList.add(...(totalTickets >= this.maxTicketsPerOrderValue ? tone.error : tone.warning));
            warning.classList.remove("hidden");
        } else {
            warning.classList.add("hidden");
        }
    }
}
