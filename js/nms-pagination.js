/**
 * @file nms-pagination.js
 * Paginate existing rendered NMS lists in the browser without changing their underlying device or rule records.
 */
(/** Initialize client-side pagination for NMS tables and repeated lists. */ function () {
	'use strict';

	var DEFAULT_SIZE = 10;
	var pageSizes = [5, 10, 25, 50, 100];

	/** Return direct matching children while excluding empty-state placeholders. */
	function directChildren(container, selector) {
		return Array.prototype.filter.call(container.children, /** Keep matching rows that do not contain an empty-state message. */ function (child) {
			return child.matches(selector) && !child.querySelector('.nms-empty');
		});
	}

	/** Build page-number tokens with ellipses around a large page range. */
	function pageTokens(current, total) {
		if (total <= 7) return Array.from({length: total}, /** Convert a zero-based array index into a displayed page number. */ function (_, index) { return index + 1; });
		var pages = [1];
		var start = Math.max(2, current - 1);
		var end = Math.min(total - 1, current + 1);
		if (start > 2) pages.push('gap-start');
		for (var page = start; page <= end; page++) pages.push(page);
		if (end < total - 1) pages.push('gap-end');
		pages.push(total);
		return pages;
	}

	/** Create an accessible pagination button with the supplied state and click action. */
	function createButton(label, title, disabled, active, onClick) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'nms-page-button' + (active ? ' current' : '');
		button.textContent = label;
		button.setAttribute('aria-label', title);
		if (active) button.setAttribute('aria-current', 'page');
		button.disabled = disabled;
		button.addEventListener('click', onClick);
		return button;
	}

	/** Attach pagination controls and track the container's current items, page, and page size. */
	function Paginator(container, itemSelector, label) {
		var self = this;
		this.container = container;
		this.items = directChildren(container, itemSelector);
		if (!this.items.length) return;
		this.page = 1;
		this.pageSize = Number(container.getAttribute('data-nms-page-size')) || DEFAULT_SIZE;
		this.label = label;
		this.controls = document.createElement('nav');
		this.controls.className = 'nms-pagination';
		this.controls.setAttribute('aria-label', label + ' pagination');
		this.controls.setAttribute('data-nms-no-tooltip', '1');
		this.summary = document.createElement('span');
		this.summary.className = 'nms-pagination-summary';
		this.actions = document.createElement('div');
		this.actions.className = 'nms-pagination-actions';
		this.controls.appendChild(this.summary);
		this.controls.appendChild(this.actions);
		container.addEventListener('nms:list-updated', /** Recapture changed list items and reset pagination to the first page. */ function () {
			self.items = directChildren(container, itemSelector);
			self.page = 1;
			self.render();
		});
		var anchor = container.closest('.nms-table-wrap') || container;
		anchor.insertAdjacentElement('afterend', this.controls);
		this.render();
	}

	Paginator.prototype.go = /** Clamp the requested page to the available range and redraw the list controls. */ function (page) {
		var totalPages = Math.max(1, Math.ceil(this.items.length / this.pageSize));
		this.page = Math.max(1, Math.min(totalPages, page));
		this.render();
	};

	Paginator.prototype.render = /** Show the current item slice and rebuild the summary, page-size selector, and navigation. */ function () {
		var self = this;
		var total = this.items.length;
		if (!total) {
			this.controls.hidden = true;
			return;
		}
		this.controls.hidden = false;
		var totalPages = Math.max(1, Math.ceil(total / this.pageSize));
		if (this.page > totalPages) this.page = totalPages;
		var start = (this.page - 1) * this.pageSize;
		var end = Math.min(start + this.pageSize, total);
		this.items.forEach(/** Hide items whose index falls outside the current page slice. */ function (item, index) {
			item.classList.toggle('nms-page-hidden', index < start || index >= end);
		});
		this.summary.textContent = 'Showing ' + (start + 1) + '–' + end + ' of ' + total;
		this.actions.innerHTML = '';

		var sizeLabel = document.createElement('label');
		sizeLabel.className = 'nms-pagination-size';
		sizeLabel.textContent = 'Rows';
		var sizeSelect = document.createElement('select');
		sizeSelect.setAttribute('aria-label', 'Rows per page');
		pageSizes.forEach(/** Add a page-size choice and mark the current size as selected. */ function (size) {
			var option = document.createElement('option');
			option.value = size;
			option.textContent = size;
			option.selected = size === self.pageSize;
			sizeSelect.appendChild(option);
		});
		sizeSelect.addEventListener('change', /** Apply a new page size and return the list to its first page. */ function () {
			self.pageSize = Number(sizeSelect.value);
			self.page = 1;
			self.render();
		});
		sizeLabel.appendChild(sizeSelect);
		this.actions.appendChild(sizeLabel);
		this.actions.appendChild(createButton('‹', 'Previous page', this.page === 1, false, /** Navigate to the previous page. */ function () { self.go(self.page - 1); }));
		pageTokens(this.page, totalPages).forEach(/** Render either a page button or an ellipsis gap for each navigation token. */ function (token) {
			if (typeof token !== 'number') {
				var gap = document.createElement('span');
				gap.className = 'nms-page-gap';
				gap.textContent = '…';
				self.actions.appendChild(gap);
				return;
			}
			self.actions.appendChild(createButton(String(token), 'Page ' + token, false, token === self.page, /** Navigate to the page represented by this numbered button. */ function () { self.go(token); }));
		});
		this.actions.appendChild(createButton('›', 'Next page', this.page === totalPages, false, /** Navigate to the next page. */ function () { self.go(self.page + 1); }));
	};

	/** Attach paginators to supported NMS tables, associations, rules, and inventory lists. */
	function initialize() {
		Array.prototype.forEach.call(document.querySelectorAll('.nms-table tbody'), /** Paginate this table's direct body rows. */ function (container, index) {
			new Paginator(container, 'tr', 'Table ' + (index + 1));
		});
		Array.prototype.forEach.call(document.querySelectorAll('.nms-association-table'), /** Paginate association entries without counting their heading row. */ function (container, index) {
			new Paginator(container, '.nms-association-row:not(.heading)', 'Association list ' + (index + 1));
		});
		Array.prototype.forEach.call(document.querySelectorAll('.nms-existing-rules'), /** Paginate the compact fault-rule entries in this container. */ function (container) {
			new Paginator(container, '.nms-compact-rule', 'Fault rules');
		});
		Array.prototype.forEach.call(document.querySelectorAll('.nms-popup-categories'), /** Paginate the device-category entries in this container. */ function (container) {
			new Paginator(container, 'div', 'Device categories');
		});
		var inventory = document.getElementById('nmsTopologyInventory');
		if (inventory) new Paginator(inventory, '.nms-inventory-card', 'Topology inventory');
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
	else initialize();
}());
