/* Decorate native Cacti pages without replacing forms, handlers or APIs. */
(function () {
 'use strict';
 function init() {
  var configNode = document.getElementById('nmsNativeConfig');
  var main = document.getElementById('main');
  if (!configNode || !main || document.getElementById('nmsSidebar')) return;
  var config = JSON.parse(configNode.textContent);
  var pluginBase = config.base + 'plugins/nms/';
  document.documentElement.classList.add('nms-core-workspace');
  document.body.classList.add('nms-standalone');
  var container = document.createElement('div');
  container.innerHTML = config.navigation;
  container.querySelectorAll('a[href]').forEach(function (link) {
   link.href = new URL(link.getAttribute('href'), location.origin + pluginBase).href;
   link.addEventListener('click', function (event) { event.stopPropagation(); });
  });
  var header = container.querySelector('.nms-app-header');
  var layout = container.querySelector('.nms-app-layout');
  document.body.prepend(header, layout);
  main.classList.add('nms-native-main');
  layout.appendChild(main);
  var toolbar = document.createElement('div');
  toolbar.className = 'nms-native-tools';
  var description = document.createElement('p');
  description.textContent = 'Templates: data input method → data source → graph → device. Use data queries for indexed tables. All fields and saves below are provided by Cacti core.';
  toolbar.appendChild(description);
   var builder = document.createElement('a');
   builder.textContent = 'Create graph template from a reading';
   builder.href = pluginBase + 'templates.php?section=graph&view=builder';
   builder.addEventListener('click', function (event) { event.stopPropagation(); });
   toolbar.appendChild(builder);
  function syncSection() {
   var section = config.pages[location.pathname.slice(config.base.length)];
   document.querySelectorAll('.nms-template-subnav a').forEach(function (link) {
    if (new URL(link.href).searchParams.get('section') === section) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
   });
   builder.hidden = section !== 'graph';
  }
  layout.insertBefore(toolbar, main);
  var toggle = document.getElementById('nmsSidebarToggle');
  toggle.addEventListener('click', function () {
   document.body.classList.remove('nms-sidebar-menu-open');
   var collapsed = document.body.classList.toggle('nms-sidebar-collapsed');
   toggle.setAttribute('aria-expanded', String(!collapsed));
   toggle.setAttribute('aria-label', collapsed ? 'Open sidebar' : 'Collapse sidebar');
  });
  document.querySelectorAll('.nms-template-menu > summary').forEach(function (menu) { menu.addEventListener('click', function (event) {
   if (document.body.classList.contains('nms-sidebar-collapsed') || (innerWidth <= 700 && !document.body.classList.contains('nms-sidebar-menu-open'))) {
    event.preventDefault();
    document.body.classList.remove('nms-sidebar-collapsed');
    if (innerWidth <= 700) document.body.classList.add('nms-sidebar-menu-open');
    this.parentElement.open = true;
    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-label', 'Collapse sidebar');
   }
  }); });
  function workspaceURL(value) {
   try {
    var url = new URL(value, location.href);
    var page = url.pathname.slice(config.base.length);
    if (url.origin === location.origin && config.pages[page] && url.searchParams.get('nms_workspace') !== 'off') url.searchParams.set('nms_workspace', 'templates');
    return url.href;
   } catch (_) { return value; }
  }
  ['pushState', 'replaceState'].forEach(function (method) {
   var nativeMethod = history[method];
   history[method] = function (state, title, url) {
    var result = nativeMethod.call(history, state, title, url ? workspaceURL(url) : url);
    syncSection();
    return result;
   };
  });
  window.addEventListener('popstate', syncSection);
  history.replaceState(history.state, '', workspaceURL(location.href));
  function decorate() {
   var listTable = main.querySelector('table.cactiTable th.tableSubHeaderCheckbox');
   main.classList.toggle('nms-native-list', Boolean(listTable));
   // Move the original controls, not copies: Cacti keeps its selected rows,
   // submit handlers, CSRF fields and confirmation workflow in the same form.
   main.querySelectorAll('.actionsDropdown').forEach(function (actions) {
    var form = actions.closest('form');
    if (!form || !actions.querySelector('select[name="drp_action"]')) return;
    var list = form.querySelector('th.tableSubHeaderCheckbox');
    if (!list) return;
    var anchor = list;
    while (anchor.parentElement && anchor.parentElement !== form) anchor = anchor.parentElement;
    if (anchor.parentElement !== form) return;
    var pager = form.querySelector('.navBarNavigation');
    if (pager && pager.parentElement === form) anchor = pager;
    actions.classList.add('nms-list-actions');
    actions.setAttribute('role', 'group');
    actions.setAttribute('aria-label', 'Actions for selected rows');
    if (actions.nextElementSibling !== anchor) form.insertBefore(actions, anchor);
   });
   main.querySelectorAll('a[href]').forEach(function (link) {
    var original = link.getAttribute('href');
    if (original && !/^(#|javascript:)/i.test(original)) {
     var next = workspaceURL(original);
     if (link.href !== next) link.href = next;
    }
   });
   main.querySelectorAll('form').forEach(function (form) {
    if (!form.querySelector('input[name="nms_workspace"]')) {
     var input = document.createElement('input');
     input.type = 'hidden'; input.name = 'nms_workspace'; input.value = 'templates'; form.appendChild(input);
    }
   });
  }
  decorate();
  new MutationObserver(decorate).observe(main, {childList: true, subtree: true});
  if (window.jQuery) window.jQuery.ajaxPrefilter(function (options) { options.url = workspaceURL(options.url); });
 }
 if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
