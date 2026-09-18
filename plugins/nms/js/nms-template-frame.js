/* Keep native Cacti editors inside the NMS shell without replacing saves/ACLs. */
(function () {
	"use strict";
	var frame = document.getElementById("nmsTemplateFrame");
	if (!frame) return;
	/**
	 * Handles decorate Frame.
	 */
	function decorateFrame() {
		var doc;
		try {
			doc = frame.contentDocument;
		} catch (_) {
			return;
		}
		if (
			!doc ||
			!doc.getElementById("main") ||
			doc.getElementById("nmsFrameStyle")
		)
			return;
		doc.documentElement.classList.add("nms-core-workspace");
		[
			"css/nms-v1.1.css",
			"css/nms-core-editor.css",
			"css/nms-tooltips.css",
		].forEach(function (path, index) {
			var link = doc.createElement("link");
			link.rel = "stylesheet";
			if (!index) link.id = "nmsFrameStyle";
			link.href =
				frame.dataset.assets +
				path +
				"?v=" +
				encodeURIComponent(frame.dataset.version);
			doc.head.appendChild(link);
		});
		var main = doc.getElementById("main");
		main.classList.add("nms-native-main");
		/**
		 * Handles decorate.
		 */
		function decorate() {
			main.querySelectorAll("a[target], form[target]").forEach(
				function (node) {
					if (
						["_top", "_parent"].indexOf(
							(node.getAttribute("target") || "").toLowerCase(),
						) >= 0
					)
						node.removeAttribute("target");
				},
			);
			// Class fallbacks for Firefox ESR releases without CSS :has().
			main.querySelectorAll(".formRow").forEach(function (row) {
				row.parentElement.classList.add("nms-form-grid");
			});
			main.querySelectorAll(".cactiTableTitle").forEach(function (title) {
				title.parentElement.classList.add("nms-title-row");
			});
			main.querySelectorAll('.formFieldName input[name^="t_"]').forEach(
				function (input) {
					var flag = input.closest(".nowrap");
					if (flag) flag.style.display = "none";
				},
			);
			main.querySelectorAll(".actionsDropdown").forEach(
				function (actions) {
					var form = actions.closest("form");
					if (
						!form ||
						!form.querySelector("th.tableSubHeaderCheckbox")
					)
						return;
					if (form.firstElementChild !== actions)
						form.insertBefore(actions, form.firstElementChild);
					actions.classList.add("nms-list-actions");
				},
			);
			main.querySelectorAll("form").forEach(function (form) {
				if (form.querySelector('[name="nms_workspace"]')) return;
				var input = doc.createElement("input");
				input.type = "hidden";
				input.name = "nms_workspace";
				input.value = "off";
				form.appendChild(input);
			});
		}
		decorate();
		new MutationObserver(decorate).observe(main, {
			childList: true,
			subtree: true,
		});
	}
	frame.addEventListener("load", decorateFrame);
	decorateFrame();
})();
