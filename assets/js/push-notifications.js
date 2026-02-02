/**
 * Push Notifications Client Script
 * LegalPress Push Notifications Plugin
 *
 * Handles push notification subscription and UI
 */

(function () {
	"use strict";

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

	// Unsubscribe from push notifications
	const unsubscribeFromPush = async (subscription) => {
		try {
			// First unsubscribe from browser
			const unsubscribed = await subscription.unsubscribe();
			console.log("[Push] Browser unsubscribe result:", unsubscribed);

			// Then notify server to remove from database
			const formData = new FormData();
			formData.append("action", "legalpress_remove_subscription");
			formData.append("nonce", legalpressPush.nonce);
			formData.append("endpoint", subscription.endpoint);

			const response = await fetch(legalpressPush.ajaxUrl, {
				method: "POST",
				body: formData,
			});

			const result = await response.json();
			console.log("[Push] Server unsubscribe result:", result);

			if (!result.success) {
				console.warn("[Push] Server removal warning:", result.data);
				// Don't throw - browser already unsubscribed
			}

			console.log("[Push] Unsubscribed successfully");
			return true;
		} catch (error) {
			console.error("[Push] Unsubscribe failed:", error);
			throw error;
		}
	};

	// Request notification permission
	const requestPermission = async () => {
		const permission = await Notification.requestPermission();
		console.log("[Push] Permission:", permission);
		return permission === "granted";
	};

	// Update UI based on subscription status
	const updateUI = (isSubscribed, button) => {
		if (!button) return;

		if (isSubscribed) {
			button.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                Notifications Enabled
            `;
			button.classList.add("push-subscribed");
			button.classList.remove("push-unsubscribed");
		} else {
			button.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                Enable Notifications
            `;
			button.classList.remove("push-subscribed");
			button.classList.add("push-unsubscribed");
		}
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
				setupPushButton(pushButton, registration, subscription);
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
			text.textContent =
				"Get the latest legal news and analysis delivered to your inbox weekly.";
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
                    Enable Notifications
                </button>
                <p class="push-note">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    We respect your privacy. Unsubscribe at any time.
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
			setupPushButton(pushButton, registration, subscription);
		} catch (error) {
			console.error("[Push] Button init failed:", error);
		}
	};

	// Setup push button click handler
	const setupPushButton = (button, registration, currentSubscription) => {
		// Prevent multiple event listeners
		if (button.dataset.listenerAttached) return;
		button.dataset.listenerAttached = "true";

		button.addEventListener("click", async () => {
			// Get fresh subscription state on each click
			let subscription = await getSubscription(registration);

			button.disabled = true;
			button.innerHTML = `
                <svg class="spin" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 6v6l4 2"/>
                </svg>
                Processing...
            `;

			try {
				if (subscription) {
					// Unsubscribe
					await unsubscribeFromPush(subscription);
					subscription = null;
					showToast("Notifications disabled", "info");
				} else {
					// Check permission
					const hasPermission = await requestPermission();
					if (!hasPermission) {
						showToast("Please allow notifications to subscribe", "error");
						updateUI(false, button);
						button.disabled = false;
						return;
					}

					// Subscribe
					subscription = await subscribeToPush(registration);
					showToast(
						"Notifications enabled! You'll be notified of new posts.",
						"success",
					);
				}

				updateUI(!!subscription, button);
			} catch (error) {
				console.error("[Push] Toggle failed:", error);
				showToast("Something went wrong. Please try again.", "error");
				updateUI(!!subscription, button);
			}

			button.disabled = false;
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

            .push-subscribe-btn:hover {
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
            }

            .push-subscribe-btn.push-subscribed:hover {
                background: #16a34a;
                border-color: #16a34a;
            }

            .push-subscribe-btn:disabled {
                opacity: 0.7;
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

            .push-subscribe-btn svg.spin {
                animation: spin 1s linear infinite;
            }

            @media (max-width: 640px) {
                .push-toast {
                    left: 1rem;
                    right: 1rem;
                    bottom: 1rem;
                    max-width: none;
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
