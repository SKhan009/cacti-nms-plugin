(function () {
	"use strict";
	const dialog = document.getElementById("nmsAppearanceDialog");
	if (!dialog) return;
	const close = () => {
		dialog.close();
	};
	dialog
		.querySelectorAll("[data-catalog-close]")
		.forEach((button) => button.addEventListener("click", close));
	dialog.showModal();
})();
