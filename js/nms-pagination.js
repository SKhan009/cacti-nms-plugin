(function () {
	'use strict';

	var DEFAULT_SIZE = 10;
	var pageSizes = [5, 10, 25, 50, 100];

	function directChildren(container, selector) {
		return Array.prototype.filter.call(container.children, function (child) {
			return child.matches(selector) && !child.querySelector('.nms-empty');
		});
	}

	function pageTokens(current, total) {
		if (total <= 7) return Array.from({length: total}, function (_, index) { return index + 1; });
		var pages = [1];
		var start = Math.max(2, current - 1);
		var end = Math.min(total - 1, current + 1);
		if (start > 2) pages.push('gap-start');
		for (var page = start; page <= end; page++) pages.push(page);
		if (end < total - 1) pages.push('gap-end');
		pages.push(total);
		return pages;
	}

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
		container.addEventListener('nms:list-updated', function () {
			self.items = directChildren(container, itemSelector);
			self.page = 1;
			self.render();
		});
		var anchor = container.closest('.nms-table-wrap') || container;
		anchor.insertAdjacentElement('afterend', this.controls);
		this.render();
	}

	Paginator.prototype.go = function (page) {
		var totalPages = Math.max(1, Math.ceil(this.items.length / this.pageSize));
		this.page = Math.max(1, Math.min(totalPages, page));
		this.render();
	};

	Paginator.prototype.render = function () {
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
		this.items.forEach(function (item, index) {
			item.classList.toggle('nms-page-hidden', index < start || index >= end);
		});
		this.summary.textContent = 'Showing ' + (start + 1) + '–' + end + ' of ' + total;
		this.actions.innerHTML = '';

		var sizeLabel = document.createElement('label');
		sizeLabel.className = 'nms-pagination-size';
		sizeLabel.textContent = 'Rows';
		var sizeSelect = document.createElement('select');
		sizeSelect.setAttribute('aria-label', 'Rows per page');
		pageSizes.forEach(function (size) {
			var option = document.createElement('option');
			option.value = size;
			option.textContent = size;
			option.selected = size === self.pageSize;
			sizeSelect.appendChild(option);
		});
		sizeSelect.addEventListener('change', function () {
			self.pageSize = Number(sizeSelect.value);
			self.page = 1;
			self.render();
		});
		sizeLabel.appendChild(sizeSelect);
		this.actions.appendChild(sizeLabel);
		this.actions.appendChild(createButton('‹', 'Previous page', this.page === 1, false, function () { self.go(self.page - 1); }));
		pageTokens(this.page, totalPages).forEach(function (token) {
			if (typeof token !== 'number') {
				var gap = document.createElement('span');
				gap.className = 'nms-page-gap';
				gap.textContent = '…';
				self.actions.appendChild(gap);
				return;
			}
			self.actions.appendChild(createButton(String(token), 'Page ' + token, false, token === self.page, function () { self.go(token); }));
		});
		this.actions.appendChild(createButton('›', 'Next page', this.page === totalPages, false, function () { self.go(self.page + 1); }));
	};

	function initialize() {
		Array.prototype.forEach.call(document.querySelectorAll('.nms-table tbody'), function (container, index) {
			new Paginator(container, 'tr', 'Table ' + (index + 1));
		});
		Array.prototype.forEach.call(document.querySelectorAll('.nms-association-table'), function (container, index) {
			new Paginator(container, '.nms-association-row:not(.heading)', 'Association list ' + (index + 1));
		});
		Array.prototype.forEach.call(document.querySelectorAll('.nms-existing-rules'), function (container) {
			new Paginator(container, '.nms-compact-rule', 'Fault rules');
		});
		Array.prototype.forEach.call(document.querySelectorAll('.nms-popup-categories'), function (container) {
			new Paginator(container, 'div', 'Device categories');
		});
		var inventory = document.getElementById('nmsTopologyInventory');
		if (inventory) new Paginator(inventory, '.nms-inventory-card', 'Topology inventory');
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
	else initialize();
}());
