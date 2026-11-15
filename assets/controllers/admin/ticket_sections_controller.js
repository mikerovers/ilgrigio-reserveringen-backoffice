import { Controller } from "@hotwired/stimulus"

// Connects to data-controller="admin--ticket-sections"
// Sits on the "sections" CollectionField of the ticket template form. Adds
// up/down buttons to every part and, before submit, writes the visual order
// into each hidden position input (the CRUD controller renumbers by it).
export default class extends Controller {
    connect() {
        this.form = this.element.closest("form")
        this.onSubmit = () => this.writePositions()
        this.form?.addEventListener("submit", this.onSubmit)

        // EasyAdmin adds/removes entries without a page reload.
        this.observer = new MutationObserver(() => this.decorate())
        this.observer.observe(this.element, { childList: true, subtree: true })
        this.decorate()
    }

    disconnect() {
        this.observer?.disconnect()
        this.form?.removeEventListener("submit", this.onSubmit)
    }

    items() {
        return Array.from(this.element.querySelectorAll(".field-collection-item"))
    }

    decorate() {
        this.items().forEach((item) => {
            if (item.querySelector(":scope .ticket-section-controls")) return

            const controls = document.createElement("div")
            controls.className = "ticket-section-controls"
            controls.append(this.button("fa fa-arrow-up", "Move up", () => this.move(item, -1)))
            controls.append(this.button("fa fa-arrow-down", "Move down", () => this.move(item, 1)))
            item.prepend(controls)
        })
    }

    button(icon, label, onClick) {
        const button = document.createElement("button")
        button.type = "button"
        button.className = "btn btn-sm btn-secondary"
        button.title = label
        button.innerHTML = `<i class="${icon}"></i>`
        button.addEventListener("click", onClick)
        return button
    }

    move(item, direction) {
        const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling
        if (!sibling || !sibling.classList.contains("field-collection-item")) return

        if (direction < 0) {
            sibling.before(item)
        } else {
            sibling.after(item)
        }
        this.writePositions()
    }

    writePositions() {
        this.items().forEach((item, index) => {
            const input = item.querySelector("[data-ticket-section-position]")
            if (input) input.value = String(index)
        })
    }
}
