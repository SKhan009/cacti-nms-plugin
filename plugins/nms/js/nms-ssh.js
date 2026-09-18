(function () {
	"use strict";
	const root = document.getElementById("ssh-presets");
	if (!root) return;
	const form = root.querySelector("form"),
		file = root.querySelector("[data-key-file]"),
		paste = form.elements.private_key,
		phrase = form.elements.passphrase,
		status = root.querySelector("[data-key-file-status]");
	const bytes = (value) => new TextEncoder().encode(value).length;
	let fileError = "",
		pending = false,
		sequence = 0;
	/**
	 * Handles key Error.
	 */
	function keyError(value) {
		if (!value || bytes(value) > 65536 || value.includes("\0"))
			return "Choose a nonempty text private key no larger than 64 KB.";
		const text = value.trim();
		if (
			/^-----BEGIN (OPENSSH PRIVATE KEY|RSA PRIVATE KEY|EC PRIVATE KEY|DSA PRIVATE KEY|PRIVATE KEY|ENCRYPTED PRIVATE KEY)-----\r?\n[\s\S]+\r?\n-----END \1-----$/.test(
				text,
			)
		)
			return "";
		if (
			/^PuTTY-User-Key-File-[23]: [^\r\n]+\r?\n/.test(text) &&
			/(?:^|\n)Private-Lines: [1-9][0-9]*\r?\n/.test(text) &&
			/(?:^|\n)Private-MAC: [a-fA-F0-9]+$/.test(text)
		)
			return "";
		return "Choose a PEM, OpenSSH or PuTTY private key. Public keys, certificates, logs and other files are not accepted.";
	}
	/**
	 * Handles render.
	 */
	function render() {
		const method = form.querySelector(
				'[name="auth_method"]:checked',
			)?.value,
			key = method === "key",
			needsCredential = form.dataset.storedAuth !== method;
		root.querySelector("[data-ssh-passphrase]").hidden = !key;
		phrase.disabled = !key;
		root.querySelectorAll("[data-ssh-credential]").forEach((field) => {
			const active = field.dataset.sshCredential === method;
			field.hidden = !active;
			field.querySelectorAll("input,textarea").forEach((input) => {
				input.disabled = !active;
				input.required = false;
			});
		});
		paste.required = key && needsCredential && !file.files.length;
		form.elements.secret.required = !key && needsCredential;
		const conflict = key && file.files.length && paste.value !== "";
		file.setCustomValidity(
			key
				? pending
					? "Wait for the key file check to finish."
					: conflict
						? "Choose a file or paste a key, not both."
						: fileError
				: "",
		);
		paste.setCustomValidity(
			key && paste.value
				? conflict
					? "Choose a file or paste a key, not both."
					: keyError(paste.value)
				: "",
		);
		phrase.setCustomValidity(
			key && (bytes(phrase.value) > 1024 || phrase.value.includes("\0"))
				? "Passphrase must be at most 1024 bytes without NUL characters."
				: key && phrase.value && !paste.value && !file.files.length
					? "Provide the private key again when changing its passphrase."
					: "",
		);
		const password = form.elements.secret;
		password.setCustomValidity(
			!key &&
				(bytes(password.value) > 65536 || password.value.includes("\0"))
				? "Password must be at most 64 KB without NUL characters."
				: "",
		);
		for (const [name, max] of [
			["name", 80],
			["description", 500],
			["username", 128],
		]) {
			const field = form.elements[name];
			field.setCustomValidity(
				name !== "description" && !field.value.trim()
					? "This field is required."
					: bytes(field.value) > max
						? "Maximum " + max + " UTF-8 bytes."
						: /[\x00-\x1f]/.test(field.value)
							? "Control characters are not allowed."
							: "",
			);
		}
		status.textContent = pending
			? "Checking private key file…"
			: fileError ||
				(conflict
					? "Choose a file or paste a key, not both."
					: file.files.length
						? "Private-key format recognized. Save verifies its contents and passphrase."
						: "Choose a file or paste its complete contents. Stored keys are never displayed.");
		status.classList.toggle("error", Boolean(fileError || conflict));
		file.setAttribute(
			"aria-invalid",
			String(Boolean(key && (fileError || conflict))),
		);
	}
	file.addEventListener("change", async () => {
		const current = ++sequence,
			selected = file.files[0];
		fileError = "";
		pending = Boolean(selected);
		render();
		if (selected) {
			let result = "";
			try {
				result =
					selected.size < 1 || selected.size > 65536
						? "Choose a nonempty private key file no larger than 64 KB."
						: keyError(await selected.text());
			} catch (_) {
				result = "Unable to read this file. Choose it again.";
			}
			if (current !== sequence) return;
			fileError = result;
		}
		pending = false;
		render();
	});
	form.addEventListener("input", render);
	form.addEventListener("change", (event) => {
		if (event.target !== file) render();
	});
	form.addEventListener("submit", (event) => {
		if (event.submitter?.value === "delete") return;
		render();
		if (!form.checkValidity()) {
			event.preventDefault();
			form.querySelectorAll("details").forEach((d) => {
				if (d.querySelector(":invalid")) d.open = true;
			});
			form.reportValidity();
		}
	});
	form.addEventListener(
		"invalid",
		(event) => {
			const details = event.target.closest("details");
			if (details) details.open = true;
		},
		true,
	);
	render();
})();
