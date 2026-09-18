/* Browser confirmation precedes a server-validated save; feedback follows a successful redirect. */
document.querySelectorAll("form[data-confirm]").forEach(function (form) {
	form.addEventListener("submit", function (event) {
		if (!window.confirm(form.dataset.confirm)) event.preventDefault();
	});
});
