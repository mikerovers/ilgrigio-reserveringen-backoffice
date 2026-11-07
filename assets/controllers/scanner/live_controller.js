import { Controller } from "@hotwired/stimulus";

// Connects to data-controller="scanner--live"
// Keeps the counters and progress bar in sync across balies. Updates arrive over
// Mercure (Server-Sent Events); a slow poll of the stats endpoint backs them up and
// is the only source when Mercure publishing is disabled.
export default class extends Controller {
    static targets = [
        "expected", "inside", "remaining", "bar", "track",
        "liveDot", "liveLabel", "statusDot", "statusLabel", "sync",
    ];
    static values = {
        eventId: String,
        statsUrl: String,
        tokenUrl: String,
        mercureEnabled: Boolean,
    };

    connect() {
        this.onStats = (event) => this.apply(event.detail);
        window.addEventListener("scanner:stats", this.onStats);

        this.timeFormat = new Intl.DateTimeFormat("nl-NL", {
            hour: "2-digit",
            minute: "2-digit",
            timeZone: "Europe/Amsterdam",
        });

        this.poll();
        this.pollTimer = window.setInterval(() => this.poll(), this.mercureEnabledValue ? 60000 : 10000);

        if (this.mercureEnabledValue) {
            this.subscribe();
        } else {
            this.setConnection("polling");
        }
    }

    disconnect() {
        window.removeEventListener("scanner:stats", this.onStats);
        window.clearInterval(this.pollTimer);
        window.clearTimeout(this.reconnectTimer);
        this.source?.close();
    }

    async poll() {
        try {
            const response = await fetch(this.statsUrlValue, { headers: { Accept: "application/json" } });
            if (response.status === 401 || response.status === 403) {
                window.location.reload();
                return;
            }
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            this.apply(await response.json());
            if (!this.mercureEnabledValue) {
                this.setConnection("polling");
            }
        } catch {
            if (!this.mercureEnabledValue) {
                this.setConnection("offline");
            }
        }
    }

    async subscribe() {
        window.clearTimeout(this.reconnectTimer);
        this.source?.close();
        this.setConnection("connecting");

        try {
            const response = await fetch(this.tokenUrlValue, { headers: { Accept: "application/json" } });
            if (response.status === 401 || response.status === 403) {
                window.location.reload();
                return;
            }
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const { hub, topic, token } = await response.json();
            const url = new URL(hub);
            url.searchParams.append("topic", topic);
            url.searchParams.set("authorization", token);

            this.source = new EventSource(url);
            this.source.onopen = () => {
                this.setConnection("online");
                this.poll(); // catch up on anything missed while disconnected
            };
            this.source.onmessage = (message) => {
                const payload = JSON.parse(message.data);
                this.apply(payload.stats);
            };
            this.source.onerror = () => {
                this.setConnection("offline");
                // CLOSED means the browser gave up (e.g. expired token): reconnect with a fresh one.
                if (this.source.readyState === EventSource.CLOSED) {
                    this.reconnectTimer = window.setTimeout(() => this.subscribe(), 3000);
                }
            };
        } catch {
            this.setConnection("offline");
            this.reconnectTimer = window.setTimeout(() => this.subscribe(), 5000);
        }
    }

    apply(stats) {
        if (!stats) {
            return;
        }
        const { expected, checkedIn, remaining } = stats;
        this.expectedTarget.textContent = expected;
        this.insideTarget.textContent = checkedIn;
        this.remainingTarget.textContent = remaining;

        const pct = expected > 0 ? Math.min(100, (checkedIn / expected) * 100) : 0;
        this.barTarget.style.width = `${pct.toFixed(1)}%`;
        this.trackTarget.setAttribute("aria-valuemax", expected);
        this.trackTarget.setAttribute("aria-valuenow", checkedIn);

        this.syncTarget.textContent = this.timeFormat.format(new Date());
    }

    setConnection(state) {
        const labels = {
            online: "Online",
            polling: "Online",
            connecting: "Verbinden…",
            offline: "Verbinding verbroken",
        };
        const color = {
            online: "bg-emerald-500",
            polling: "bg-emerald-500",
            connecting: "bg-amber-400",
            offline: "bg-red-500",
        }[state];
        const live = state === "online" || state === "polling";

        this.statusLabelTarget.textContent = labels[state];
        [this.statusDotTarget, this.liveDotTarget].forEach((dot) => {
            dot.classList.remove("bg-gray-300", "bg-emerald-500", "bg-amber-400", "bg-red-500");
            dot.classList.add(color);
        });
        this.liveLabelTarget.textContent = live ? "Live" : "Offline";
    }
}
