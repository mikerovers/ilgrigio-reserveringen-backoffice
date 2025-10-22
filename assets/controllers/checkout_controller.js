import { Controller } from "@hotwired/stimulus"

// Connects to data-controller="checkout"
export default class extends Controller {
    static targets = [
        "firstName", "lastName", "companyName", "city", "phoneNumber",
        "email", "emailConfirm", "emailMatchError",
        "terms", "form", "submitButton",
        "couponInput", "applyCouponButton", "couponStatus",
        "subtotal", "discount", "total", "discountRow", "tax"
    ]

    static values = {
        subtotal: Number,
        discountAmount: Number,
        total: Number,
        appliedCoupon: Object,
        taxRate: Number
    }

    connect() {
        this.appliedCoupon = null
        this.isSubmitting = false

        // Check if there's a pre-applied coupon from the backend
        if (this.appliedCouponValue && Object.keys(this.appliedCouponValue).length > 0) {
            this.appliedCoupon = this.appliedCouponValue
            this.showCouponSuccess(this.appliedCoupon)
        }

        this.setupEmailValidation()
        this.updateTotals()
    }

    setupEmailValidation() {
        // Real-time email validation
        this.emailTarget.addEventListener('input', () => this.validateEmailFormat())
        this.emailConfirmTarget.addEventListener('input', () => this.validateEmailMatch())
        
        // Form submission validation
        this.formTarget.addEventListener('submit', (event) => this.validateForm(event))
    }

    validateEmailFormat() {
        const email = this.emailTarget.value
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
        
        if (email && !emailRegex.test(email)) {
            this.emailTarget.classList.add('border-red-500', 'focus:ring-red-500')
            this.emailTarget.classList.remove('border-green-500', 'focus:ring-green-500')
            return false
        } else if (email) {
            this.emailTarget.classList.add('border-green-500', 'focus:ring-green-500')
            this.emailTarget.classList.remove('border-red-500', 'focus:ring-red-500')
            return true
        } else {
            this.emailTarget.classList.remove('border-red-500', 'border-green-500', 'focus:ring-red-500', 'focus:ring-green-500')
            return false
        }
    }

    validateEmailMatch() {
        const email = this.emailTarget.value
        const emailConfirm = this.emailConfirmTarget.value
        
        if (emailConfirm && email !== emailConfirm) {
            this.emailConfirmTarget.classList.add('border-red-500', 'focus:ring-red-500')
            this.emailConfirmTarget.classList.remove('border-green-500', 'focus:ring-green-500')
            this.emailMatchErrorTarget.classList.remove('hidden')
            return false
        } else if (emailConfirm && email === emailConfirm && this.validateEmailFormat()) {
            this.emailConfirmTarget.classList.add('border-green-500', 'focus:ring-green-500')
            this.emailConfirmTarget.classList.remove('border-red-500', 'focus:ring-red-500')
            this.emailMatchErrorTarget.classList.add('hidden')
            return true
        } else if (!emailConfirm) {
            this.emailConfirmTarget.classList.remove('border-red-500', 'border-green-500', 'focus:ring-red-500', 'focus:ring-green-500')
            this.emailMatchErrorTarget.classList.add('hidden')
            return false
        }
    }

    validateForm(event) {
        // Prevent duplicate submissions
        if (this.isSubmitting) {
            event.preventDefault()
            return
        }

        let isValid = true

        // Validate required fields
        const requiredFields = [
            { target: this.firstNameTarget, name: 'Voornaam' },
            { target: this.lastNameTarget, name: 'Achternaam' },
            { target: this.cityTarget, name: 'Plaats' },
            { target: this.emailTarget, name: 'E-mailadres' },
            { target: this.emailConfirmTarget, name: 'E-mailadres bevestiging' }
        ]

        requiredFields.forEach(field => {
            if (!field.target.value.trim()) {
                field.target.classList.add('border-red-500', 'focus:ring-red-500')
                field.target.classList.remove('border-green-500', 'focus:ring-green-500')
                isValid = false
            } else {
                field.target.classList.add('border-green-500', 'focus:ring-green-500')
                field.target.classList.remove('border-red-500', 'focus:ring-red-500')
            }
        })

        // Validate email format and match
        if (!this.validateEmailFormat() || !this.validateEmailMatch()) {
            isValid = false
        }

        // Validate terms acceptance
        if (!this.termsTarget.checked) {
            this.showError('Je moet akkoord gaan met de algemene voorwaarden.')
            isValid = false
        }        

        if (!isValid) {
            event.preventDefault()
            // Scroll to first error field
            const firstErrorField = this.element.querySelector('.border-red-500')
            if (firstErrorField) {
                firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' })
                firstErrorField.focus()
            }
        } else {
            // Mark as submitting and disable the submit button
            this.isSubmitting = true
            this.disableSubmitButton();
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
        this.applyCouponButtonTarget.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Valideren...'

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
                
                // Reload the page to get updated session values from backend
                setTimeout(() => {
                    window.location.reload()
                }, 1500)
            } else {
                this.showCouponError(result.message || 'Kortingscode niet geldig')
            }
        } catch (error) {
            console.error('Error validating coupon:', error)
            this.showCouponError('Fout bij het valideren van kortingscode')
        } finally {
            // Reset button state
            this.applyCouponButtonTarget.disabled = false
            this.applyCouponButtonTarget.innerHTML = 'Toepassen'
        }
    }

    showCouponSuccess(coupon) {
        this.couponStatusTarget.innerHTML = `
            <div class="flex items-center justify-between bg-green-100 border border-green-400 text-green-700 px-3 py-2 rounded text-sm mt-2">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>Kortingscode toegepast: <strong>${coupon.code}</strong></span>
                </div>
                <button type="button" class="text-green-600 hover:text-green-800" data-action="click->checkout#removeCoupon">
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
            <div class="bg-red-100 border border-red-400 text-red-700 px-3 py-2 rounded text-sm mt-2">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                ${message}
            </div>
        `
        this.couponStatusTarget.classList.remove('hidden')
        
        // Auto-hide error after 5 seconds
        setTimeout(() => {
            if (this.couponStatusTarget.innerHTML.includes('bg-red-100')) {
                this.couponStatusTarget.classList.add('hidden')
            }
        }, 5000)
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
                
                // Reload page to get updated session values
                window.location.reload()
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
            this.updateTotals()
        }
    }

    updateTotals() {
        // Backend now passes subtotal WITHOUT tax, and total WITH tax
        // We should just display these values directly from the backend
        const subtotal = this.subtotalValue  // Already without tax from backend
        const total = this.totalValue  // Already with tax from backend
        const discount = this.discountAmountValue

        // Calculate tax from the difference
        const taxRate = (this.taxRateValue || 9) / 100
        const tax = total - subtotal

        // Update display
        if (this.hasSubtotalTarget) {
            this.subtotalTarget.textContent = `€${subtotal.toFixed(2).replace('.', ',')}`
        }

        if (this.hasTaxTarget) {
            this.taxTarget.textContent = `€${tax.toFixed(2).replace('.', ',')}`
        }

        if (this.hasTotalTarget) {
            this.totalTarget.textContent = `€${total.toFixed(2).replace('.', ',')}`
        }

        if (this.hasDiscountTarget && discount > 0) {
            this.discountTarget.textContent = `-€${discount.toFixed(2).replace('.', ',')}`
            if (this.hasDiscountRowTarget) {
                this.discountRowTarget.classList.remove('hidden')
            }
        } else if (this.hasDiscountRowTarget) {
            this.discountRowTarget.classList.add('hidden')
        }
    }

    disableSubmitButton() {
        if (this.hasSubmitButtonTarget) {
            this.submitButtonTarget.disabled = true
            this.submitButtonTarget.classList.add('opacity-50', 'cursor-not-allowed')
            this.submitButtonTarget.innerHTML = `
                <i class="fas fa-spinner fa-spin mr-2"></i>
                Bestelling wordt verwerkt...
            `
        }
    }

    showError(message) {
        // Create or update error message
        let errorDiv = this.element.querySelector('.checkout-error-message')

        if (!errorDiv) {
            errorDiv = document.createElement('div')
            errorDiv.className = 'checkout-error-message bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4'
            errorDiv.innerHTML = `<i class="fas fa-exclamation-triangle mr-2"></i>${message}`
            this.formTarget.insertBefore(errorDiv, this.formTarget.firstChild)
        } else {
            errorDiv.innerHTML = `<i class="fas fa-exclamation-triangle mr-2"></i>${message}`
        }

        // Auto-hide after 5 seconds
        setTimeout(() => {
            if (errorDiv && errorDiv.parentNode) {
                errorDiv.remove()
            }
        }, 5000)
    }
}
