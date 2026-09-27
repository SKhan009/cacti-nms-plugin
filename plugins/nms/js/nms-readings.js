/**
 * @file nms-readings.js
 * Filter device-reading evidence while keeping its client-side paginator in sync.
 */
document.addEventListener("DOMContentLoaded", function () {
	"use strict";

    var devicePicker = document.querySelector("[data-reading-device]");
    if (devicePicker) {
        devicePicker.addEventListener("change", function () {
            devicePicker.form.requestSubmit();
        });
    }


	var rows = document.querySelectorAll("[data-reading-row]");
	var tabs = document.querySelectorAll("[data-reading-tab]");
	var view = document.querySelector("[data-reading-view]");
	var tableBody = document.querySelector(".nms-reading-table tbody");
	var active = document.querySelector("[data-reading-active]");
	var currentCategory = active ? active.dataset.readingActive : (view ? view.value : "all");

	/** Return whether a reading belongs in the active category view. */
	function matches(row, category) {
		var categoryMatch =
			category === "all" ||
			row.dataset.category === category ||
			(category === "problems" && row.dataset.problem === "1");
		return categoryMatch;
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

        var commands = new Set();
        var evidenceCount = 0;
        document.querySelectorAll("[data-reading-evidence]").forEach(function (block) {
            var visible = matches(block, category);
            if (visible && block.dataset.command) {
                var key = block.textContent;
                visible = !commands.has(key);
                commands.add(key);
            }
            block.hidden = !visible;
            if (visible) evidenceCount++;
        });
        var empty = document.querySelector("[data-reading-evidence-empty]");
        if (empty) empty.hidden = evidenceCount > 0;

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


	/** Reapply an early filter once the common paginator has been constructed. */
	document.addEventListener("nms:paginator-ready", function (event) {
		if (event.target === tableBody) apply(currentCategory);
	});

	apply(currentCategory);
});
