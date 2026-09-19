/**
 * @file nms-readings.js
 * Filter device-reading evidence while keeping its client-side paginator in sync.
 */
document.addEventListener("DOMContentLoaded", function () {
	"use strict";

	var rows = document.querySelectorAll("[data-reading-row]");
	var tabs = document.querySelectorAll("[data-reading-tab]");
	var view = document.querySelector("[data-reading-view]");
	var protocol = document.querySelector("[data-reading-protocol]");
	var tableBody = document.querySelector(".nms-reading-table tbody");
	var currentCategory = view ? view.value : "all";

	/** Return whether a reading belongs in the active category and protocol view. */
	function matches(row, category) {
		var categoryMatch =
			category === "all" ||
			row.dataset.category === category ||
			(category === "problems" && row.dataset.problem === "1");
		var protocolMatch =
			!protocol ||
			protocol.value === "all" ||
			row.dataset.protocol === protocol.value;
		return categoryMatch && protocolMatch;
	}

	/** Apply the selected view through the paginator so page counts and rows agree. */
	function apply(category) {
		currentCategory = category;
		var filter = function (row) {
			return matches(row, category);
		};

		tabs.forEach(function (tab) {
			tab.classList.toggle("active", tab.dataset.readingTab === category);
		});
		if (view) view.value = category;

		if (tableBody && tableBody.nmsPaginator) {
			rows.forEach(function (row) {
				row.hidden = false;
			});
			tableBody.nmsPaginator.setFilter(filter);
			return;
		}

		rows.forEach(function (row) {
			row.hidden = !filter(row);
		});
	}

	tabs.forEach(function (tab) {
		tab.addEventListener("click", function () {
			apply(tab.dataset.readingTab);
		});
	});
	if (view) view.addEventListener("change", function () { apply(view.value); });
	if (protocol)
		protocol.addEventListener("change", function () {
			apply(currentCategory);
		});

	/** Reapply an early filter once the common paginator has been constructed. */
	document.addEventListener("nms:paginator-ready", function (event) {
		if (event.target === tableBody) apply(currentCategory);
	});

	apply(currentCategory);
});
