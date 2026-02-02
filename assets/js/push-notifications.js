/**
 * Push Notifications Client Script
 * LegalPress Push Notifications Plugin
 *
 * Handles push notification subscription and UI
 * Subscribe-only mode with auto-trigger
 */

(function () {
"use strict";

// Configuration
const AUTO_TRIGGER_DELAY = 3000; // 3 seconds after page load
const PROMPT_COOLDOWN_DAYS = 7; // Days before showing prompt again if dismissed

// Check for push notification support
const isPushSupported = () => {
return (
"serviceWorker" in navigator &&
"PushManager" in window &&
"Notification" in window
);
};

// Convert VAPID key from base64url to Uint8Array
const urlBase64ToUint8Array = (base64String) => {
const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
const base64 = (base64String + padding)
.replace(/-/g, "+")
.replace(/_/g, "/");

const rawData = window.atob(base64);
const outputArray = new Uint8Array(rawData.length);

for (let i = 0; i < rawData.length; ++i) {
outputArray[i] = rawData.charCodeAt(i);
}
return outputArray;
};

// Register service worker
const registerServiceWorker = async () => {
try {
const registration = await navigator.serviceWorker.register(
legalpressPush.serviceWorkerUrl,
{ scope: "/" },
);
console.log("[Push] Service worker registered:", registration.scope);
return registration;
} catch (error) {
console.error("[Push] Service worker registration failed:", error);
throw error;
}
};

// Get existing subscription
const getSubscription = async (registration) => {
return await registration.pushManager.getSubscription();
};

// Subscribe to push notifications
const subscribeToPush = async (registration) => {
try {
const subscription = await registration.pushManager.subscribe({
userVisibleOnly: true,
applicationServerKey: urlBase64ToUint8Array(
legalpressPush.vapidPublicKey,
),
});

console.log("[Push] Subscribed:", subscription.endpoint);

// Send subscription to server
await saveSubscription(subscription);

return subscription;
} catch (error) {
console.error("[Push] Subscribe failed:", error);
throw error;
}
};

// Save subscription to server
const saveSubscription = async (subscription) => {
const formData = new FormData();
formData.append("action", "legalpress_save_subscription");
formData.append("nonce", legalpressPush.nonce);
formData.append("subscription", JSON.stringify(subscription.toJSON()));

const response = await fetch(legalpressPush.ajaxUrl, {
method: "POST",
body: formData,
});

const result = await response.json();

if (!result.success) {
throw new Error(result.data || "Failed to save subscription");
}

console.log("[Push] Subscription saved to server");
return result;
};

// Request notification permission
const requestPermission = async () => {
const permission = await Notification.requestPermission();
console.log("[Push] Permission:", permission);
return permission === "granted";
};

// Check if auto-prompt should be shown
const shouldShowAutoPrompt = () => {
const lastPrompt = localStorage.getItem("legalpress_push_prompt_time");
if (!lastPrompt) return true;

const daysSincePrompt = (Date.now() - parseInt(lastPrompt)) / (1000 * 60 * 60 * 24);
return daysSincePrompt >= PROMPT_COOLDOWN_DAYS;
};

// Mark prompt as shown
const markPromptShown = () => {
localStorage.setItem("legalpress_push_prompt_time", Date.now().toString());
};

// Update UI based on subscription status
const updateUI = (isSubscribed, button) => {
if (!button) return;

if (isSubscribed) {
button.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    <polyline points="20 6 9 17 4 12" style="stroke: currentColor;"/>
                </svg>
                Subscribed
            `;
button.classList.add("push-subscribed");
button.classList.remove("push-unsubscribed");
button.disabled = true;
button.style.cursor = "default";
} else {
button.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                Subscribe
            `;
button.classList.remove("push-subscribed");
button.classList.add("push-unsubscribed");
button.disabled = false;
button.style.cursor = "pointer";
}
};

// Auto-trigger subscription prompt
const autoTriggerSubscription = async (registration) => {
// Check if already subscribed
const subscription = await getSubscription(registration);
if (subscription) {
console.log("[Push] Already subscribed, skipping auto-trigger");
return;
}

// Check if permission already denied
if (Notification.permission === "denied") {
console.log("[Push] Notifications denied, skipping auto-trigger");
return;
}

// Check cooldown
if (!shouldShowAutoPrompt()) {
console.log("[Push] Auto-prompt in cooldown period");
return;
}

// Show auto-prompt modal
showAutoPromptModal(registration);
};

// Show auto-prompt modal
const showAutoPromptModal = (registration) => {
// Don't show if already visible
if (document.querySelector(".push-auto-modal")) return;

const modal = document.createElement("div");
modal.className = "push-auto-modal";
modal.innerHTML = `
            <div class="push-auto-modal__backdrop"></div>
            <div class="push-auto-modal__content">
                <button type="button" class="push-auto-modal__close" aria-label="Close">&times;</button>
                <div class="push-auto-modal__icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                </div>
                <h3 class="push-auto-modal__title">Stay Updated!</h3>
                <p class="push-auto-modal__text">Get instant notifications when we publish new legal articles and updates.</p>
                <button type="button" class="push-auto-modal__subscribe">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    Subscribe Now
                </button>
                <p class="push-auto-modal__note">You can disable notifications anytime from your browser settings.</p>
            </div>
        `;

document.body.appendChild(modal);

// Animate in
requestAnimationFrame(() => {
modal.classList.add("push-auto-modal--visible");
});

// Close handlers
const closeModal = () => {
markPromptShown();
modal.classList.remove("push-auto-modal--visible");
setTimeout(() => modal.remove(), 300);
};

modal.querySelector(".push-auto-modal__close").addEventListener("click", closeModal);
modal.querySelector(".push-auto-modal__backdrop").addEventListener("click", closeModal);

// Subscribe handler
modal.querySelector(".push-auto-modal__subscribe").addEventListener("click", async (e) => {
const btn = e.currentTarget;
btn.disabled = true;
btn.innerHTML = `
                <svg class="spin" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                </svg>
                Subscribing...
            `;

try {
const hasPermission = await requestPermission();
if (hasPermission) {
await subscribeToPush(registration);
showToast("Subscribed! You will receive notifications for new posts.", "success");

// Update any subscription buttons on page
const pushButton = document.querySelector(".push-subscribe-btn");
if (pushButton) {
updateUI(true, pushButton);
}
} else {
showToast("Please allow notifications in your browser to subscribe.", "info");
}
} catch (error) {
console.error("[Push] Auto-subscribe failed:", error);
showToast("Something went wrong. Please try again.", "error");
}

closeModal();
});

// Close on Escape key
const escHandler = (e) => {
if (e.key === "Escape") {
closeModal();
document.removeEventListener("keydown", escHandler);
}
};
document.addEventListener("keydown", escHandler);
};

// Main initialization
const init = async () => {
// Check support
if (!isPushSupported()) {
console.log("[Push] Push notifications not supported");
return;
}

// Check if VAPID key is available
if (!legalpressPush.vapidPublicKey) {
console.log("[Push] VAPID public key not configured");
return;
}

// Get newsletter form and convert to push subscription
const newsletterBox = document.querySelector(".newsletter-box");

if (newsletterBox) {
// Convert newsletter section to push notifications
convertNewsletterToPush(newsletterBox);
}

// Register service worker
try {
const registration = await registerServiceWorker();
const subscription = await getSubscription(registration);

// Update button state
const pushButton = document.querySelector(".push-subscribe-btn");
if (pushButton) {
updateUI(!!subscription, pushButton);
if (!subscription) {
setupPushButton(pushButton, registration);
}
}

// Auto-trigger after delay (only if not already subscribed)
if (!subscription) {
setTimeout(() => {
autoTriggerSubscription(registration);
}, AUTO_TRIGGER_DELAY);
}
} catch (error) {
console.error("[Push] Initialization failed:", error);
}
};

// Convert newsletter section to push notifications
const convertNewsletterToPush = (container) => {
const content = container.querySelector(".newsletter-box__content");
if (!content) return;

// Update title and text
const title = content.querySelector(".newsletter-box__title");
const text = content.querySelector(".newsletter-box__text");

if (title) {
title.textContent = "Stay Updated";
}
if (text) {
text.textContent = "Get the latest legal news and analysis delivered instantly.";
}

// Replace form with push button
const form = content.querySelector(".newsletter-form");
if (form) {
const pushContainer = document.createElement("div");
pushContainer.className = "push-notification-container";
pushContainer.innerHTML = `
                <button type="button" class="push-subscribe-btn btn btn-primary push-unsubscribed">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    Subscribe
                </button>
                <p class="push-note">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    We respect your privacy. Manage in browser settings.
                </p>
            `;
form.replaceWith(pushContainer);

// Initialize push button
initPushButton();
}
};

// Initialize push button after DOM modification
const initPushButton = async () => {
const pushButton = document.querySelector(".push-subscribe-btn");
if (!pushButton || pushButton.dataset.initialized) return;

// Mark as initialized to prevent double event binding
pushButton.dataset.initialized = "true";

try {
const registration = await navigator.serviceWorker.ready;
const subscription = await getSubscription(registration);

updateUI(!!subscription, pushButton);
if (!subscription) {
setupPushButton(pushButton, registration);
}
} catch (error) {
console.error("[Push] Button init failed:", error);
}
};

// Setup push button click handler (subscribe only)
const setupPushButton = (button, registration) => {
// Prevent multiple event listeners
if (button.dataset.listenerAttached) return;
button.dataset.listenerAttached = "true";

button.addEventListener("click", async () => {
// Get fresh subscription state
const existingSubscription = await getSubscription(registration);

// If already subscribed, just update UI and return
if (existingSubscription) {
updateUI(true, button);
return;
}

button.disabled = true;
button.innerHTML = `
                <svg class="spin" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                </svg>
                Subscribing...
            `;

try {
// Check permission
const hasPermission = await requestPermission();
if (!hasPermission) {
showToast("Please allow notifications in your browser to subscribe.", "info");
updateUI(false, button);
return;
}

// Subscribe
await subscribeToPush(registration);
showToast("Subscribed! You will receive notifications for new posts.", "success");
updateUI(true, button);
} catch (error) {
console.error("[Push] Subscribe failed:", error);
showToast("Something went wrong. Please try again.", "error");
updateUI(false, button);
}
});
};

// Show toast notification
const showToast = (message, type = "info") => {
// Remove existing toast
const existingToast = document.querySelector(".push-toast");
if (existingToast) {
existingToast.remove();
}

const toast = document.createElement("div");
toast.className = `push-toast push-toast--${type}`;
toast.innerHTML = `
            <span class="push-toast__message">${message}</span>
            <button type="button" class="push-toast__close">&times;</button>
        `;

document.body.appendChild(toast);

// Animate in
requestAnimationFrame(() => {
toast.classList.add("push-toast--visible");
});

// Close button
toast.querySelector(".push-toast__close").addEventListener("click", () => {
toast.classList.remove("push-toast--visible");
setTimeout(() => toast.remove(), 300);
});

// Auto-hide after 5 seconds
setTimeout(() => {
if (toast.parentNode) {
toast.classList.remove("push-toast--visible");
setTimeout(() => toast.remove(), 300);
}
}, 5000);
};

// Add push notification styles
const addStyles = () => {
const style = document.createElement("style");
style.textContent = `
            .push-notification-container {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 1rem;
            }

            .push-subscribe-btn {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.875rem 2rem;
                font-size: 1rem;
                font-weight: 600;
                border-radius: 0.5rem;
                cursor: pointer;
                transition: all 0.3s ease;
                background: var(--color-accent, #d4a84b);
                color: var(--color-primary-dark, #1a2634);
                border: 2px solid var(--color-accent, #d4a84b);
            }

            .push-subscribe-btn:hover:not(:disabled) {
                background: var(--color-accent-dark, #c49a40);
                border-color: var(--color-accent-dark, #c49a40);
                transform: translateY(-2px);
            }

            .push-subscribe-btn svg {
                flex-shrink: 0;
            }

            .push-subscribe-btn.push-subscribed {
                background: #22c55e;
                border-color: #22c55e;
                color: #fff;
                cursor: default;
            }

            .push-subscribe-btn:disabled {
                opacity: 0.9;
                cursor: not-allowed;
                transform: none;
            }

            .push-note {
                display: flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 0.875rem;
                color: var(--color-text-tertiary, #6b7280);
            }

            /* Dark mode styles */
            [data-theme="dark"] .push-subscribe-btn {
                background: var(--color-accent, #d4a84b);
                color: #1a2634;
                border-color: var(--color-accent, #d4a84b);
            }

            [data-theme="dark"] .push-subscribe-btn.push-subscribed {
                background: #22c55e;
                border-color: #22c55e;
                color: #fff;
            }

            [data-theme="dark"] .push-note {
                color: var(--color-text-secondary, #cbd5e1);
            }

            /* Auto-prompt modal */
            .push-auto-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 99999;
                display: flex;
                align-items: center;
                justify-content: center;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.3s ease, visibility 0.3s ease;
            }

            .push-auto-modal--visible {
                opacity: 1;
                visibility: visible;
            }

            .push-auto-modal__backdrop {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.6);
                backdrop-filter: blur(4px);
            }

            .push-auto-modal__content {
                position: relative;
                background: var(--color-bg-primary, #fff);
                border-radius: 1rem;
                padding: 2rem;
                max-width: 400px;
                width: 90%;
                text-align: center;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                transform: scale(0.9) translateY(20px);
                transition: transform 0.3s ease;
            }

            .push-auto-modal--visible .push-auto-modal__content {
                transform: scale(1) translateY(0);
            }

            [data-theme="dark"] .push-auto-modal__content {
                background: var(--color-bg-secondary, #1e293b);
            }

            .push-auto-modal__close {
                position: absolute;
                top: 1rem;
                right: 1rem;
                background: none;
                border: none;
                font-size: 1.5rem;
                cursor: pointer;
                color: var(--color-text-tertiary, #6b7280);
                padding: 0.25rem;
                line-height: 1;
                transition: color 0.2s ease;
            }

            .push-auto-modal__close:hover {
                color: var(--color-text-primary, #1a2634);
            }

            [data-theme="dark"] .push-auto-modal__close:hover {
                color: var(--color-text-primary, #f1f5f9);
            }

            .push-auto-modal__icon {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 80px;
                height: 80px;
                margin: 0 auto 1.5rem;
                background: linear-gradient(135deg, var(--color-accent, #d4a84b) 0%, #f5d68a 100%);
                border-radius: 50%;
                color: var(--color-primary-dark, #1a2634);
            }

            .push-auto-modal__title {
                font-size: 1.5rem;
                font-weight: 700;
                color: var(--color-text-primary, #1a2634);
                margin: 0 0 0.75rem;
            }

            [data-theme="dark"] .push-auto-modal__title {
                color: var(--color-text-primary, #f1f5f9);
            }

            .push-auto-modal__text {
                font-size: 1rem;
                color: var(--color-text-secondary, #64748b);
                margin: 0 0 1.5rem;
                line-height: 1.6;
            }

            [data-theme="dark"] .push-auto-modal__text {
                color: var(--color-text-secondary, #94a3b8);
            }

            .push-auto-modal__subscribe {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
                width: 100%;
                padding: 1rem 2rem;
                font-size: 1rem;
                font-weight: 600;
                border-radius: 0.5rem;
                cursor: pointer;
                transition: all 0.3s ease;
                background: var(--color-accent, #d4a84b);
                color: var(--color-primary-dark, #1a2634);
                border: none;
            }

            .push-auto-modal__subscribe:hover:not(:disabled) {
                background: var(--color-accent-dark, #c49a40);
                transform: translateY(-2px);
            }

            .push-auto-modal__subscribe:disabled {
                opacity: 0.7;
                cursor: not-allowed;
                transform: none;
            }

            .push-auto-modal__note {
                font-size: 0.75rem;
                color: var(--color-text-tertiary, #94a3b8);
                margin: 1rem 0 0;
            }

            /* Toast styles */
            .push-toast {
                position: fixed;
                bottom: 2rem;
                right: 2rem;
                max-width: 400px;
                padding: 1rem 1.5rem;
                background: var(--color-bg-elevated, #fff);
                color: var(--color-text-primary, #1a2634);
                border-radius: 0.5rem;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
                display: flex;
                align-items: center;
                gap: 1rem;
                z-index: 10000;
                transform: translateX(120%);
                transition: transform 0.3s ease;
            }

            [data-theme="dark"] .push-toast {
                background: var(--color-bg-elevated, #1e293b);
                color: var(--color-text-primary, #f1f5f9);
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
            }

            .push-toast--visible {
                transform: translateX(0);
            }

            .push-toast--success {
                border-left: 4px solid #22c55e;
            }

            .push-toast--error {
                border-left: 4px solid #ef4444;
            }

            .push-toast--info {
                border-left: 4px solid #3b82f6;
            }

            .push-toast__message {
                flex: 1;
            }

            .push-toast__close {
                background: none;
                border: none;
                font-size: 1.5rem;
                cursor: pointer;
                opacity: 0.5;
                padding: 0;
                line-height: 1;
                color: inherit;
            }

            .push-toast__close:hover {
                opacity: 1;
            }

            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }

            .push-subscribe-btn svg.spin,
            .push-auto-modal__subscribe svg.spin {
                animation: spin 1s linear infinite;
            }

            @media (max-width: 640px) {
                .push-toast {
                    left: 1rem;
                    right: 1rem;
                    bottom: 1rem;
                    max-width: none;
                }

                .push-auto-modal__content {
                    padding: 1.5rem;
                    margin: 1rem;
                }

                .push-auto-modal__icon {
                    width: 64px;
                    height: 64px;
                }

                .push-auto-modal__icon svg {
                    width: 32px;
                    height: 32px;
                }
            }
        `;
document.head.appendChild(style);
};

// Initialize when DOM is ready
if (document.readyState === "loading") {
document.addEventListener("DOMContentLoaded", () => {
addStyles();
init();
});
} else {
addStyles();
init();
}
})();
