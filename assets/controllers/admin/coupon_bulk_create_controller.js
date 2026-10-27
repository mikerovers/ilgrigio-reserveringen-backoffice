import { Controller } from "@hotwired/stimulus"

// Connects to data-controller="admin--coupon-bulk-create"
// Admin-only controller for the "bulk create coupons" screen. Fetches
// DB-verified unique codes from the backend and appends them to the codes
// textarea; the request is never trusted to generate codes client-side, so
// uniqueness is always checked against existing coupons.
export default class extends Controller {
    static targets = ["prefix", "amount", "quantity", "codes", "prefillButton", "discountType", "amountLabel", "amountHelp"]

    static values = {
        generateUrl: String,
        csrfToken: String,
    }

    connect() {
        this.updateAmountMax()
    }

    discountTypeChanged() {
        this.updateAmountMax()
    }

    updateAmountMax() {
        const selected = this.discountTypeTargets.find((input) => input.checked)

        if (selected?.value === "percent") {
            this.amountTarget.setAttribute("max", "100")
            this.amountLabelTarget.textContent = "Percentage"
            this.amountHelpTarget.textContent = "Enter a whole percentage from 1 to 100. Applies to every code below."
        } else if (selected?.value === "by_ticket") {
            this.amountTarget.removeAttribute("max")
            this.amountLabelTarget.textContent = "Tickets to make free"
            this.amountHelpTarget.textContent = "The highest-priced tickets in the cart will be free. Applies to every code below."
        } else {
            this.amountTarget.removeAttribute("max")
            this.amountLabelTarget.textContent = "Amount"
            this.amountHelpTarget.textContent = "Enter a whole euro amount. Applies to every code below."
        }
    }

    async prefill() {
        const prefix = this.prefixTarget.value.trim()
        const amount = this.amountTarget.value.trim()
        const quantity = parseInt(this.quantityTarget.value, 10) || 0

        if (!prefix || !amount || quantity < 1) {
            alert("Fill in Prefix, Amount, and a Quantity of at least 1 first.")
            return
        }

        const params = new URLSearchParams()
        params.set("prefix", prefix)
        params.set("amount", amount)
        params.set("quantity", quantity)

        this.prefillButtonTarget.disabled = true

        try {
            const response = await fetch(this.generateUrlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                    "X-CSRF-Token": this.csrfTokenValue,
                },
                body: params.toString(),
            })

            if (!response.ok) {
                throw new Error("Failed to generate codes")
            }

            const data = await response.json()
            const existing = this.codesTarget.value.trim()
            this.codesTarget.value = existing ? `${existing}\n${data.codes.join("\n")}` : data.codes.join("\n")
        } catch (error) {
            console.error("Error generating coupon codes:", error)
            alert("Could not generate codes. Please try again.")
        } finally {
            this.prefillButtonTarget.disabled = false
        }
    }
}
