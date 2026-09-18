/* Local modal implementation: does not require the browser Popover API. */
(function () {
	var panel = document.getElementById("nmsSimulatorStatus");
	var label = document.getElementById("nmsSimulatorStatusLabel");
	if (!panel || !label || !window.fetch) return;
	/**
	 * Handles update.
	 */
	function update() {
		if (document.hidden) {
			setTimeout(update, 5000);
			return;
		}
		fetch("devices.php?nms_status=snmpsim", {
			credentials: "same-origin",
			cache: "no-store",
		})
			.then(function (response) {
				if (!response.ok) throw new Error("Unavailable");
				return response.json();
			})
			.then(function (status) {
				if (
					["ready", "offline", "warning"].indexOf(status.tone) < 0 ||
					typeof status.label !== "string"
				)
					throw new Error("Invalid status");
				panel.classList.remove("ready", "offline", "warning");
				panel.classList.add(status.tone);
				label.textContent = status.label;
			})
			.catch(function () {
				panel.classList.remove("ready", "offline");
				panel.classList.add("warning");
				label.textContent = "SNMPSim — Status unavailable";
			})
			.then(function () {
				setTimeout(update, 2000);
			});
	}
	setTimeout(update, 500);
})();
(function () {
	"use strict";
	var dialog = document.getElementById("nmsUploadDialog");
	if (!dialog) return;
	var trigger = document.getElementById("nmsUploadTrigger");
	var backdrop = document.createElement("div");
	backdrop.className = "nms-upload-backdrop";
	backdrop.hidden = true;
	document.body.appendChild(backdrop);
	document.body.appendChild(dialog);
	if (trigger) trigger.removeAttribute("popovertarget");
	var previousFocus;
	/**
	 * Handles close.
	 */
	function close() {
		dialog.hidden = true;
		backdrop.hidden = true;
		dialog.classList.remove("nms-upload-inline");
		if (trigger) trigger.setAttribute("aria-expanded", "false");
		document.body.classList.remove("nms-upload-open");
		if (previousFocus) previousFocus.focus();
	}
	/**
	 * Handles open.
	 */
	function open() {
		previousFocus = document.activeElement;
		dialog.classList.remove("nms-upload-inline");
		dialog.hidden = false;
		backdrop.hidden = false;
		if (trigger) trigger.setAttribute("aria-expanded", "true");
		document.body.classList.add("nms-upload-open");
		dialog.focus();
	}
	if (trigger)
		trigger.addEventListener("click", function (event) {
			event.preventDefault();
			open();
		});
	backdrop.addEventListener("click", close);
	var closeButton = dialog.querySelector(".nms-upload-dialog-close");
	if (closeButton) closeButton.removeAttribute("popovertarget");
	if (closeButton) closeButton.addEventListener("click", close);
	dialog.addEventListener("keydown", function (event) {
		if (event.key === "Escape") {
			event.preventDefault();
			close();
		}
		if (event.key !== "Tab") return;
		var controls = Array.prototype.filter.call(
			dialog.querySelectorAll(
				'a[href], button, input, select, textarea, [tabindex="0"]',
			),
			function (el) {
				return !el.disabled && el.getClientRects().length > 0;
			},
		);
		if (!controls.length) {
			event.preventDefault();
			return;
		}
		var first = controls[0],
			last = controls[controls.length - 1];
		if (
			event.shiftKey &&
			(document.activeElement === first ||
				document.activeElement === dialog)
		) {
			event.preventDefault();
			last.focus();
		} else if (
			!event.shiftKey &&
			(document.activeElement === last ||
				document.activeElement === dialog)
		) {
			event.preventDefault();
			first.focus();
		}
	});
	if (dialog.getAttribute("data-auto-open") === "true") {
		open();
		previousFocus = trigger;
	}
})();

/* Discovery configuration uses the same contained popup presentation as uploads. */
(function () {
	"use strict";
	var dialog = document.getElementById("nmsConfigDialog");
	if (!dialog) return;
	var opener = document.querySelector("[data-nms-config-open]");
	/**
	 * Handles open.
	 */
	function open() {
		if (!dialog.open) dialog.showModal();
	}
	if (opener) opener.addEventListener("click", open);
	dialog
		.querySelectorAll("[data-nms-config-close]")
		.forEach(function (button) {
			button.addEventListener("click", function () {
				dialog.close();
			});
		});
	dialog.addEventListener("close", function () {
		if (opener) opener.focus();
		else {
			var add = document.querySelector(".nms-panel-action");
			if (add) add.focus();
		}
	});
	if (dialog.dataset.autoOpen === "true") open();
})();
