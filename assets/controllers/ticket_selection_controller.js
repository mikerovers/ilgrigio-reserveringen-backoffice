import { Controller } from "@hotwired/stimulus"

// Connects to data-controller="ticket-selection"
export default class extends Controller {
    static targets = [
        "quantity", "orderItems", "totalTickets", "totalPrice", "subtotalPrice", 
        "proceedButton", "errorContainer", "errorMessage", 
        "couponInput", "applyCouponButton", "couponStatus", "discountRow", 
        "discountAmount", "couponDescription", "appliedCouponField", "discountAmountField",
        "maxTicketsWarning", "taxAmount"
    ]
    static values = { 
        ticketTypes: Array,
        eventPermalink: String,
        maxTicketsPerOrder: Number,
        taxRate: Number,
        sharedStock: Number
    }

    connect() {
        this.appliedCoupon = null
        
        // Check if there's a pre-applied coupon from session
        const appliedCouponField = this.appliedCouponFieldTarget
        if (appliedCouponField && appliedCouponField.value) {
            try {
                this.appliedCoupon = JSON.parse(appliedCouponField.value)
                if (this.appliedCoupon) {
                    this.showCouponSuccess(this.appliedCoupon)
                }
            } catch (e) {
                console.log('Error parsing applied coupon:', e)
            }
        }
        
        this.updateOrderSummary()
    }

    decreaseQuantity(event) {
        const ticketId = event.currentTarget.dataset.ticketId
        const input = this.element.querySelector(`#quantity-${ticketId}`)
        
        if (parseInt(input.value) > 0) {
            input.value = parseInt(input.value) - 1
            this.updateOrderSummary()
        }
    }

    increaseQuantity(event) {
        const ticketId = event.currentTarget.dataset.ticketId
        const input = this.element.querySelector(`#quantity-${ticketId}`)
        
        // Check if increasing would exceed maximum allowed per order
        const currentTotal = this.calculateTotalTickets()
        if (currentTotal + 1 > this.maxTicketsPerOrderValue) {
            return
        }
        
        // Check if increasing would exceed shared stock
        if (this.sharedStockValue && currentTotal + 1 > this.sharedStockValue) {
            this.showStockWarning()
            return
        }
        
        input.value = parseInt(input.value) + 1
        this.updateOrderSummary()
    }

    quantityChanged(event) {
        const input = event.target
        let value = parseInt(input.value) || 0
        const min = parseInt(input.getAttribute('min')) || 0
        
        // Ensure value is not below minimum (0)
        if (value < min) {
            value = min
            input.value = value
        }
        
        // Check if total tickets would exceed shared stock
        const currentTotal = this.calculateTotalTickets()
        if (this.sharedStockValue && currentTotal > this.sharedStockValue) {
            // If the total exceeds shared stock, reduce this input to stay within limit
            const excessAmount = currentTotal - this.sharedStockValue
            const adjustedValue = Math.max(0, value - excessAmount)
            if (adjustedValue !== value) {
                input.value = adjustedValue
                this.showStockWarning()
            }
        }
        
        // Check if total tickets would exceed maximum allowed per order
        if (currentTotal > this.maxTicketsPerOrderValue) {
            // If the total exceeds, reduce this input to stay within limit
            const excessAmount = currentTotal - this.maxTicketsPerOrderValue
            const adjustedValue = Math.max(0, value - excessAmount)
            if (adjustedValue !== value) {
                input.value = adjustedValue
            }
        }
        
        this.updateOrderSummary()
    }
    
    calculateTotalTickets() {
        let total = 0
        this.quantityTargets.forEach(input => {
            if (!input.disabled) {
                total += parseInt(input.value) || 0
            }
        })
        return total
    }

    validateForm(event) {
        event.preventDefault()
        
        // Hide previous errors
        this.hideError()
        
        // Check if at least one ticket is selected
        const totalTickets = this.calculateTotalTickets()
        
        if (totalTickets === 0) {
            this.showError('Selecteer minimaal één ticket om door te gaan.')
            return false
        }
        
        // Check if total tickets exceed shared stock
        if (this.sharedStockValue && totalTickets > this.sharedStockValue) {
            this.showError('Niet genoeg tickets beschikbaar. Er zijn nog ' + this.sharedStockValue + ' tickets beschikbaar.')
            return false
        }
        
        // Check if total tickets exceed maximum allowed per order
        if (totalTickets > this.maxTicketsPerOrderValue) {
            this.showError('Groepen vanaf 25 personen kunnen in aanmerking komen voor korting mits zij van tevoren gereserveerd hebben. Voor informatie over groepskortingen ontvangen wij graag een e-mail (info@ilgrigio.nl).')
            return false
        }
        
        // Validate individual ticket quantities (only check minimum)
        let hasError = false
        this.quantityTargets.forEach(input => {
            if (!input.disabled) {
                const quantity = parseInt(input.value) || 0
                const min = parseInt(input.getAttribute('min'))
                
                if (quantity < min) {
                    hasError = true
                }
            }
        })
        
        if (hasError) {
            this.showError('Controleer de geselecteerde aantallen. Sommige waarden zijn ongeldig.')
            return false
        }
        
        // If validation passes, submit the form
        this.element.submit()
    }
    
    showError(message) {
        this.errorMessageTarget.textContent = message
        this.errorContainerTarget.classList.remove('hidden')
        
        // Scroll to error
        this.errorContainerTarget.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'center' 
        })
    }
    
    hideError() {
        this.errorContainerTarget.classList.add('hidden')
    }

    showStockWarning() {
        if (this.hasMaxTicketsWarningTarget) {
            this.maxTicketsWarningTarget.innerHTML = `
                <i class="fas fa-info-circle mr-1"></i>
                Er zijn nog maar ${this.sharedStockValue} kaarten beschikbaar voor deze show.
            `
            this.maxTicketsWarningTarget.classList.remove('hidden', 'text-gray-500', 'bg-gray-50', 'text-amber-600', 'bg-amber-50', 'border-amber-200')
            this.maxTicketsWarningTarget.classList.add('text-red-600', 'bg-red-50', 'border-red-200')
        }
    }

    async applyCoupon() {
        const couponCode = this.couponInputTarget.value.trim()
        
        if (!couponCode) {
            this.showCouponError('Voer een kortingscode in')
            return
        }

        // Disable button and show loading state
        this.applyCouponButtonTarget.disabled = true
        this.applyCouponButtonTarget.textContent = 'Valideren...'

        try {
            const response = await fetch('/api/validate-coupon', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ code: couponCode })
            })

            const result = await response.json()

            if (result.valid) {
                this.appliedCoupon = result
                this.showCouponSuccess(result)
                this.updateOrderSummary()
                
                // Update hidden form fields
                this.appliedCouponFieldTarget.value = JSON.stringify(result)
            } else {
                this.showCouponError('Kortingscode niet geldig')
            }
        } catch (error) {
            console.error('Error validating coupon:', error)
            this.showCouponError('Fout bij het valideren van kortingscode')
        } finally {
            // Reset button state
            this.applyCouponButtonTarget.disabled = false
            this.applyCouponButtonTarget.textContent = 'Toepassen'
        }
    }

    showCouponSuccess(coupon) {
        this.couponStatusTarget.innerHTML = `
            <div class="flex items-center justify-between bg-green-100 border border-green-400 text-green-700 px-3 py-2 rounded text-sm">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>Kortingscode toegepast: <strong>${coupon.code}</strong></span>
                </div>
                <button type="button" class="text-green-600 hover:text-green-800" data-action="click->ticket-selection#removeCoupon">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `
        this.couponStatusTarget.classList.remove('hidden')
        this.couponInputTarget.disabled = true
        this.applyCouponButtonTarget.disabled = true
    }

    showCouponError(message) {
        this.couponStatusTarget.innerHTML = `
            <div class="bg-red-100 border border-red-400 text-red-700 px-3 py-2 rounded text-sm">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                ${message}
            </div>
        `
        this.couponStatusTarget.classList.remove('hidden')
    }

    async removeCoupon() {
        try {
            // Call API to remove coupon from session
            const response = await fetch('/api/remove-coupon', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            })

            const result = await response.json()

            if (result.success) {
                // Clear frontend state
                this.appliedCoupon = null
                this.couponStatusTarget.classList.add('hidden')
                this.couponInputTarget.value = ''
                this.couponInputTarget.disabled = false
                this.applyCouponButtonTarget.disabled = false
                this.appliedCouponFieldTarget.value = ''
                this.updateOrderSummary()
            } else {
                console.error('Failed to remove coupon:', result.message)
            }
        } catch (error) {
            console.error('Error removing coupon:', error)
            // Fallback to frontend-only removal
            this.appliedCoupon = null
            this.couponStatusTarget.classList.add('hidden')
            this.couponInputTarget.value = ''
            this.couponInputTarget.disabled = false
            this.applyCouponButtonTarget.disabled = false
            this.appliedCouponFieldTarget.value = ''
            this.updateOrderSummary()
        }
    }

    calculateDiscount(subtotal) {
        if (!this.appliedCoupon || !this.appliedCoupon.valid) {
            return 0
        }

        const amount = parseFloat(this.appliedCoupon.amount)
        const discountType = this.appliedCoupon.discount_type

        switch (discountType) {
            case 'percent':
                return subtotal * (amount / 100)
            case 'fixed_cart':
                return Math.min(amount, subtotal)
            default:
                return 0
        }
    }

    updateOrderSummary() {
        let totalTickets = 0
        let subtotalPrice = 0
        let orderHTML = ''

        this.quantityTargets.forEach(input => {
            // Skip disabled inputs (sold out tickets)
            if (input.disabled) {
                return
            }
            
            const quantity = parseInt(input.value)
            if (quantity > 0) {
                const ticketId = input.dataset.ticketId
                const price = parseFloat(input.dataset.price)
                const ticketType = this.ticketTypesValue.find(t => t.id == ticketId)
                
                if (ticketType) {
                    totalTickets += quantity
                    subtotalPrice += quantity * price
                    
                    orderHTML += `
                        <div class="flex justify-between items-center">
                            <div>
                                <span class="font-medium">${quantity}x ${ticketType.name}</span>
                                <div class="text-sm text-gray-600">€${price.toFixed(2)} per stuk</div>
                            </div>
                            <span class="font-semibold">€${(quantity * price).toFixed(2)}</span>
                        </div>
                    `
                }
            }
        })

        if (totalTickets === 0) {
            orderHTML = '<p class="text-gray-500 text-center">Geen tickets geselecteerd</p>'
        }

        // Calculate discount on the total (which includes tax)
        const discountAmount = this.calculateDiscount(subtotalPrice)
        const totalAfterDiscount = subtotalPrice - discountAmount

        // Calculate tax components (tax is included in the total)
        const taxRate = (this.taxRateValue || 9) / 100 // Convert percentage to decimal, default to 9%
        const taxAmount = totalAfterDiscount - (totalAfterDiscount / (1 + taxRate))
        const subtotalWithoutTax = totalAfterDiscount - taxAmount

        const finalTotal = totalAfterDiscount

        // Update display
        this.orderItemsTarget.innerHTML = orderHTML
        this.totalTicketsTarget.textContent = totalTickets
        this.subtotalPriceTarget.textContent = `€${subtotalWithoutTax.toFixed(2)}`
        this.taxAmountTarget.textContent = `€${taxAmount.toFixed(2)}`
        this.totalPriceTarget.textContent = `€${finalTotal.toFixed(2)}`

        // Update discount display
        if (discountAmount > 0 && this.appliedCoupon) {
            this.discountAmountTarget.textContent = `-€${discountAmount.toFixed(2)}`
            this.couponDescriptionTarget.textContent = this.appliedCoupon.code
            this.discountRowTarget.classList.remove('hidden')
            this.discountAmountFieldTarget.value = discountAmount.toFixed(2)
        } else {
            this.discountRowTarget.classList.add('hidden')
            this.discountAmountFieldTarget.value = '0'
        }
        
        // Enable/disable proceed button
        this.proceedButtonTarget.disabled = totalTickets === 0
        
        // Show/hide maximum tickets warning
        if (this.hasMaxTicketsWarningTarget) {
            // Check if we're near or at shared stock limit
            if (this.sharedStockValue && totalTickets >= this.sharedStockValue) {
                this.maxTicketsWarningTarget.innerHTML = `
                    <i class="fas fa-info-circle mr-1"></i>
                    Er zijn nog maar ${this.sharedStockValue} kaarten beschikbaar voor deze show.
                `
                this.maxTicketsWarningTarget.classList.remove('hidden', 'text-gray-500', 'bg-gray-50', 'text-amber-600', 'bg-amber-50', 'border-amber-200')
                this.maxTicketsWarningTarget.classList.add('text-red-600', 'bg-red-50', 'border-red-200')
            } else if (totalTickets >= this.maxTicketsPerOrderValue - 5) { // Show warning when within 5 tickets of limit
                this.maxTicketsWarningTarget.innerHTML = `
                    <i class="fas fa-info-circle mr-1"></i>
                    Maximum ${this.maxTicketsPerOrderValue} tickets per bestelling. Groepen vanaf 25 personen kunnen in aanmerking komen voor korting mits zij van tevoren gereserveerd hebben. Voor informatie over groepskortingen ontvangen wij graag een e-mail (info@ilgrigio.nl).
                `
                this.maxTicketsWarningTarget.classList.remove('hidden')
                if (totalTickets >= this.maxTicketsPerOrderValue) {
                    this.maxTicketsWarningTarget.classList.add('text-red-600', 'bg-red-50', 'border-red-200')
                    this.maxTicketsWarningTarget.classList.remove('text-gray-500', 'bg-gray-50', 'text-amber-600', 'bg-amber-50', 'border-amber-200')
                } else {
                    this.maxTicketsWarningTarget.classList.add('text-amber-600', 'bg-amber-50', 'border-amber-200')
                    this.maxTicketsWarningTarget.classList.remove('text-gray-500', 'bg-gray-50', 'text-red-600', 'bg-red-50', 'border-red-200')
                }
            } else {
                this.maxTicketsWarningTarget.classList.add('hidden')
            }
        }
        
        // Hide errors when user makes changes
        if (this.hasErrorContainerTarget) {
            this.hideError()
        }
    }
}
