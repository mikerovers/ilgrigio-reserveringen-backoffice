const STATUS_CLASSES = {
    success: [
        "flex",
        "items-center",
        "justify-between",
        "bg-green-100",
        "border",
        "border-green-400",
        "text-green-700",
        "px-3",
        "py-2",
        "rounded",
        "text-sm",
    ],
    error: [
        "bg-red-100",
        "border",
        "border-red-400",
        "text-red-700",
        "px-3",
        "py-2",
        "rounded",
        "text-sm",
    ],
};

/**
 * Renders the "coupon applied" status block into `statusTarget`, wiring the
 * remove button to `data-action="click->${controllerName}#removeCoupon"`.
 */
export function renderCouponSuccess(statusTarget, coupon, controllerName) {
    statusTarget.replaceChildren();

    const wrapper = document.createElement("div");
    wrapper.classList.add(...STATUS_CLASSES.success);

    const label = document.createElement("div");
    label.classList.add("flex", "items-center");

    const icon = document.createElement("i");
    icon.classList.add("fas", "fa-check-circle", "mr-2");
    label.appendChild(icon);

    const text = document.createElement("span");
    text.append("Kortingscode toegepast: ");
    const strong = document.createElement("strong");
    strong.textContent = coupon.code;
    text.appendChild(strong);
    label.appendChild(text);

    const removeButton = document.createElement("button");
    removeButton.type = "button";
    removeButton.classList.add("text-green-600", "hover:text-green-800");
    removeButton.dataset.action = `click->${controllerName}#removeCoupon`;
    const removeIcon = document.createElement("i");
    removeIcon.classList.add("fas", "fa-times");
    removeButton.appendChild(removeIcon);

    wrapper.append(label, removeButton);
    statusTarget.appendChild(wrapper);
    statusTarget.classList.remove("hidden");
}

/**
 * Renders a coupon error message into `statusTarget`.
 */
export function renderCouponError(statusTarget, message) {
    statusTarget.replaceChildren();

    const wrapper = document.createElement("div");
    wrapper.classList.add(...STATUS_CLASSES.error);

    const icon = document.createElement("i");
    icon.classList.add("fas", "fa-exclamation-triangle", "mr-2");

    wrapper.append(icon, document.createTextNode(message));
    statusTarget.appendChild(wrapper);
    statusTarget.classList.remove("hidden");
}

/**
 * POSTs a coupon code to /api/validate-coupon and returns the parsed
 * CouponResponse JSON. Throws on network/parse failure.
 */
export async function validateCoupon(code) {
    const response = await fetch("/api/validate-coupon", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
        },
        body: JSON.stringify({ code }),
    });

    return response.json();
}

/**
 * POSTs to /api/remove-coupon and returns the parsed JSON response.
 * Throws on network/parse failure.
 */
export async function removeCoupon() {
    const response = await fetch("/api/remove-coupon", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
        },
    });

    return response.json();
}
