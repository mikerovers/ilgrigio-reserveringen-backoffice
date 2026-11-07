import { Controller } from "@hotwired/stimulus";

// Connects to data-controller="scanner--clock"
// Footer date and time in venue (Amsterdam) time, independent of the device's timezone.
export default class extends Controller {
    static targets = ["date", "time"];

    connect() {
        this.dateFormat = new Intl.DateTimeFormat("nl-NL", {
            day: "numeric",
            month: "long",
            year: "numeric",
            timeZone: "Europe/Amsterdam",
        });
        this.timeFormat = new Intl.DateTimeFormat("nl-NL", {
            hour: "2-digit",
            minute: "2-digit",
            timeZone: "Europe/Amsterdam",
        });
        this.tick();
        this.timer = window.setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        window.clearInterval(this.timer);
    }

    tick() {
        const now = new Date();
        this.dateTargets.forEach((el) => (el.textContent = this.dateFormat.format(now)));
        this.timeTargets.forEach((el) => (el.textContent = this.timeFormat.format(now)));
    }
}
