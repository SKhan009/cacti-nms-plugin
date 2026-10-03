"use strict";
// Errors stay beside their form; success notifications never interrupt editing.
const messageDialog = document.querySelector("#message-dialog");
function icctInlineMessage({title, text, danger = false, context}) {
  const target = context || document.querySelector('.device-panel') || document.querySelector('main');
  if (!target) return;
  let feedback = [...target.children].find(node => node.classList.contains('inline-feedback'));
  if (!feedback) {
    feedback = document.createElement(target.matches('.field') ? 'span' : 'div');
    feedback.className = 'inline-feedback';
    target.matches('.field') ? target.append(feedback) : target.prepend(feedback);
  }
  feedback.classList.toggle('error', danger);
  feedback.setAttribute('role', danger ? 'alert' : 'status');
  const heading = document.createElement('strong'), body = document.createElement('span');
  heading.textContent = title;
  body.textContent = text;
  feedback.replaceChildren(heading, body);
}
function icctToast({title = 'Success', text}) {
  let region = document.querySelector('#toast-region');
  if (!region) {
    region = document.createElement('div');region.id = 'toast-region';
    region.setAttribute('aria-label', 'Notifications');document.body.append(region);
  }
  const toast = document.createElement('div');toast.className = 'notification-toast';
  toast.setAttribute('role', 'status');
  const heading = document.createElement('strong'), body = document.createElement('span'), close = document.createElement('button');
  heading.textContent = title;body.textContent = text;
  close.type = 'button';close.textContent = '×';close.setAttribute('aria-label', 'Dismiss notification');
  toast.append(heading, body, close);region.append(toast);
  let timer;
  const dismiss = () => {clearTimeout(timer);toast.remove();};
  const schedule = () => {clearTimeout(timer);timer = setTimeout(dismiss, 6000);};
  close.addEventListener('click', dismiss);
  toast.addEventListener('mouseenter', () => clearTimeout(timer));toast.addEventListener('mouseleave', schedule);
  toast.addEventListener('focusin', () => clearTimeout(timer));toast.addEventListener('focusout', schedule);
  schedule();
}
function icctQueueToast(message) {
  try { sessionStorage.setItem('icct-next-toast', JSON.stringify(message)); }
  catch (_) { icctToast(message); }
}
function icctShowMessage({title = "Information", text, confirm = false, danger = false, accept = "OK", context, inline = false}) {
  if (!confirm) {
    if (danger || inline) icctInlineMessage({title, text, danger, context});
    else icctToast({title, text});
    return Promise.resolve(true);
  }
  if (!messageDialog || messageDialog.open) return Promise.resolve(false);
  const acceptButton = document.querySelector("#message-confirm");
  document.querySelector("#message-title").textContent = title;
  document.querySelector("#message-text").textContent = text;
  document.querySelector("#message-cancel").hidden = false;
  acceptButton.textContent = accept;acceptButton.classList.toggle("danger", danger);
  messageDialog.returnValue = "";messageDialog.setAttribute("role", "alertdialog");
  messageDialog.showModal();document.querySelector("#message-cancel").focus();
  return new Promise(resolve => messageDialog.addEventListener("close", () => resolve(messageDialog.returnValue === "accepted"), {once: true}));
}
if (messageDialog) {
  document.querySelector("#message-close").addEventListener("click", () => messageDialog.close("cancelled"));
  document.querySelector("#message-cancel").addEventListener("click", () => messageDialog.close("cancelled"));
  document.querySelector("#message-confirm").addEventListener("click", () => messageDialog.close("accepted"));
}
document.querySelectorAll('.feedback:not(.error)').forEach(feedback => {
  feedback.hidden = true;icctToast({title:'Success', text:feedback.textContent.trim()});
});
try {
  const queued = sessionStorage.getItem('icct-next-toast');
  sessionStorage.removeItem('icct-next-toast');
  if (queued) icctToast(JSON.parse(queued));
} catch (_) { /* Notifications do not require browser storage. */ }
document.addEventListener('invalid', event => {
  event.preventDefault();
  const field = event.target, panel = field.closest('.protocol-item');
  if (panel) {panel.hidden = false;panel.open = true;}
  const label = field.getAttribute('aria-label') || field.closest('.field')?.querySelector('.field-label')?.textContent.trim() || 'Field';
  field.setAttribute('aria-invalid', 'true');
  icctInlineMessage({title:'Check your entry', text:`${label}: ${field.validationMessage}`, danger:true, context:field.closest('.field') || field.form});
}, true);
document.addEventListener('input', event => {
  if (!event.target.matches('input, select, textarea')) return;
  event.target.removeAttribute('aria-invalid');
  event.target.closest('.field')?.querySelector('.inline-feedback')?.remove();
});

// UI only: all writes are native authenticated, CSRF-protected POST forms.
// Inventory views share one saved row set; filtering and pagination never change stored records.
const table = document.querySelector("#inventory-table");
if (table) {
  const body = table.tBodies[0];
  const rows = Array.from(body.rows);
  const filters = ["segment", "status", "rack"].map((key) => ({
    key,
    element: document.querySelector(`#filter-${key}`),
  }));
  const search = document.querySelector("#inventory-search");
  const size = document.querySelector("#page-size");
  const pageSelect = document.querySelector("#page-number");
  let page = 1,
    ascending = true,
    view = "table";
  filters.forEach(({ key, element }) => {
    [...new Set(rows.map((row) => row.dataset[key]).filter(Boolean))]
      .sort((a, b) => a.localeCompare(b))
      .forEach((value) => element.add(new Option(value, value)));
    element.addEventListener("change", () => {
      page = 1;
      render();
    });
  });
  // Apply filters before paging, then rebuild the table or segment tree from the same visible rows.
  function render() {
    const query = search.value.trim().toLocaleLowerCase();
    const matches = rows.filter(
      (row) =>
        filters.every(
          ({ key, element }) =>
            !element.value || row.dataset[key] === element.value,
        ) && row.dataset.search.toLocaleLowerCase().includes(query),
    );
    matches.sort(
      (a, b) =>
        (ascending ? 1 : -1) *
        a.dataset.name.localeCompare(b.dataset.name, undefined, {
          numeric: true,
        }),
    );
    const limit = Number(size.value),
      pages = Math.max(1, Math.ceil(matches.length / limit));
    page = Math.max(1, Math.min(page, pages));
    pageSelect.replaceChildren(
      ...Array.from(
        { length: pages },
        (_, i) => new Option(String(i + 1), String(i + 1)),
      ),
    );
    pageSelect.value = String(page);
    rows.forEach((row) => (row.hidden = true));
    const visible = matches.slice((page - 1) * limit, page * limit);
    visible.forEach((row) => {
      body.append(row);
      row.hidden = false;
    });
    document.querySelector("#result-count").textContent = matches.length
      ? `${(page - 1) * limit + 1}–${Math.min(page * limit, matches.length)} of ${matches.length} items`
      : "0 items";
    document.querySelector("#page-count").textContent = `of ${pages} pages`;
    document.querySelector("#page-previous").disabled = page === 1;
    document.querySelector("#page-next").disabled = page === pages;
    document.querySelector("#empty-inventory").hidden = matches.length > 0;
    document.querySelector("#clear-filters").disabled =
      !query && filters.every(({ element }) => !element.value);
    const tree = document.querySelector("#inventory-tree");
    tree.replaceChildren();
    if (view === "tree") {
      const groups = new Map();
      visible.forEach((row) => {
        const key = row.dataset.segment || "Unassigned segment";
        if (!groups.has(key)) {
          const details = document.createElement("details");
          details.open = true;
          const summary = document.createElement("summary");
          summary.textContent = key;
          details.append(summary);
          tree.append(details);
          groups.set(key, details);
        }
        const item = document.createElement("div");
        item.className = "tree-device";
        const link = document.createElement("a");
        link.href = `device.php?id=${row.dataset.id}&view=1`;
        link.textContent = row.dataset.name;
        const status = document.createElement("span");
        status.textContent = row.dataset.status;
        const rack = document.createElement("small");
        rack.textContent = row.dataset.rack;
        item.append(link, rack, status);
        groups.get(key).append(item);
      });
    }
    table.closest(".table-scroll").hidden = view === "tree";
    tree.hidden = view !== "tree";
  }
  search.addEventListener("input", () => {
    page = 1;
    render();
  });
  size.addEventListener("change", () => {
    page = 1;
    render();
  });
  pageSelect.addEventListener("change", () => {
    page = Number(pageSelect.value);
    render();
  });
  document.querySelector("#page-previous").addEventListener("click", () => {
    page--;
    render();
  });
  document.querySelector("#page-next").addEventListener("click", () => {
    page++;
    render();
  });
  document.querySelector("#clear-filters").addEventListener("click", () => {
    search.value = "";
    filters.forEach(({ element }) => (element.value = ""));
    page = 1;
    render();
  });
  document.querySelector("#sort-name").addEventListener("click", () => {
    ascending = !ascending;
    table.tHead.rows[0].cells[0].setAttribute(
      "aria-sort",
      ascending ? "ascending" : "descending",
    );
    document.querySelector("#sort-name span").textContent = ascending
      ? "↑"
      : "↓";
    render();
  });
  document.querySelectorAll("[data-view]").forEach((button) =>
    button.addEventListener("click", () => {
      view = button.dataset.view;
      document.querySelectorAll("[data-view]").forEach((b) => {
        b.classList.toggle("active", b === button);
        b.setAttribute("aria-pressed", String(b === button));
      });
      document.querySelector(".breadcrumb").lastChild.textContent =
        ` / ${view === "tree" ? "Tree" : "Table"} View`;
      render();
    }),
  );
  render();
}
// Confirmation dialog submits to the native collector queue controller.
const discoveryDialog = document.querySelector("#discovery-dialog");
if (discoveryDialog) {
  document
    .querySelector("#device-discovery")
    .addEventListener("click", () => discoveryDialog.showModal());
  discoveryDialog
    .querySelector("[data-close-dialog]")
    .addEventListener("click", () => discoveryDialog.close());
}
// Keep the character counter aligned with the textarea limit.
const notes = document.querySelector('[name="notes"]');
if (notes) {
  const update = () =>
    (document.querySelector("#notes-count").textContent =
      `${notes.value.length}/${notes.maxLength}`);
  notes.addEventListener("input", update);
  update();
}
// Restrict displayed placement choices to the selected site and rack capacity.
// Server-side validation remains authoritative for capacity and occupied units.
const rackData = document.querySelector("#rack-data");
if (rackData) {
  const racks = JSON.parse(rackData.textContent),
    site = document.querySelector('[name="site_id"]'),
    rack = document.querySelector('[name="rack_id"]'),
    position = document.querySelector('[name="rack_position"]');
  function updateRacks() {
    Array.from(rack.options).forEach((option) => {
      const record = racks.find((r) => String(r.id) === option.value);
      option.disabled = !!record && String(record.site_id) !== site.value;
    });
    const record = racks.find((r) => String(r.id) === rack.value);
    Array.from(position.options).forEach((option) => {
      const [start, height] = option.value.split(":").map(Number);
      option.disabled =
        !!option.value &&
        (!record || start + height - 1 > Number(record.unit_count));
    });
  }
  site.addEventListener("change", updateRacks);
  rack.addEventListener("change", updateRacks);
  updateRacks();
}
// SNMP version radios retain native numeric values and switch only relevant fields.
const versions = document.querySelectorAll('[name="snmp_version"]');
if (versions.length) {
  const update = () => {
    const selected = document.querySelector('[name="snmp_version"]:checked');
    document.querySelector(".snmp-v3-fields").hidden =
      !selected || selected.value !== "3";
    document.querySelector(".snmp-community").hidden =
      !!selected && selected.value === "3";
    const security =
      document.querySelector('[name="snmp_security_level"]:checked')?.value ||
      "noAuthNoPriv";
    const v3 = selected?.value === "3";
    // Disabled hidden credentials are omitted on submit, preserving saved secrets.
    // Username and context remain available at every V3 security level.
    const groups = {
      auth: ["snmp_auth_protocol", "snmp_password", "snmp_password_confirm"],
      privacy: [
        "snmp_priv_protocol",
        "snmp_priv_passphrase",
        "snmp_priv_confirm",
      ],
    };
    for (const [group, names] of Object.entries(groups)) {
      const visible =
        v3 &&
        (group === "auth"
          ? security !== "noAuthNoPriv"
          : security === "authPriv");
      names.forEach((name) => {
        const field = document.querySelector(`[name="${name}"]`);
        if (!field) return;
        field.closest(".field").hidden = !visible;
        field.disabled = !visible || !!field.closest("fieldset[disabled]");
      });
    }
  };
  versions.forEach((control) => control.addEventListener("change", update));
  document
    .querySelectorAll('[name="snmp_security_level"]')
    .forEach((control) => control.addEventListener("change", update));
  update();
}
// Removing an unsaved section only changes presentation; saved assignments use authenticated POST.
document.querySelectorAll(".delete-protocol").forEach((button) => {
  button.addEventListener("click", async (event) => {
    event.preventDefault();
    event.stopPropagation();
    const item = button.closest(".protocol-item");
    const name = item
      .querySelector("summary > span:nth-child(2)")
      .textContent.trim();
    const accepted = await icctShowMessage({
      title: "Remove protocol",
      text: `Remove ${name} from this device?`,
      confirm: true,
      danger: true,
      accept: "Remove",
    });
    if (!accepted) return;
    if (document.querySelector("#device-wizard") || item.dataset.saved !== "1") {
      item.hidden = true;
      return;
    }
    const form = document.querySelector("#remove-protocol-form");
    form.elements.protocol.value = item.id.replace("protocol-", "");
    form.requestSubmit();
  });
});
// Open the requested protocol panel without submitting any form.
const picker = document.querySelector("#protocol-picker");
if (picker) {
  const trigger = document.querySelector("#add-protocol");
  const backdrop = document.createElement("div");
  backdrop.className = "protocol-picker-backdrop";
  backdrop.hidden = true;
  backdrop.setAttribute("aria-hidden", "true");
  document.body.append(backdrop);
  trigger.setAttribute("aria-controls", picker.id);
  picker.setAttribute("role", "dialog");
  picker.setAttribute("aria-label", "Select protocols");
  picker.setAttribute("aria-modal", "true");
  const closePicker = () => {
    picker.hidden = true;
    backdrop.hidden = true;
    trigger.setAttribute("aria-expanded", "false");
    trigger.focus();
  };
  trigger.addEventListener("click", () => {
    picker.hidden = !picker.hidden;
    backdrop.hidden = picker.hidden;
    trigger.setAttribute("aria-expanded", String(!picker.hidden));
    if (!picker.hidden) picker.querySelector("input").focus();
  });
  backdrop.addEventListener("click", closePicker);
  window.addEventListener("hashchange", () => { if (!picker.hidden) closePicker(); });
  picker.addEventListener("keydown", event => {
    if (event.key === "Escape") { event.preventDefault(); closePicker(); }
    if (event.key === "Tab") {
      const controls = [...picker.querySelectorAll("input:not(:disabled), button:not(:disabled)")];
      const first = controls[0], last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
  const options = Array.from(picker.querySelectorAll("[data-protocol-target]"));
  const confirm = picker.querySelector("#confirm-protocols");
  const updatePicker = () => {
    confirm.disabled = !options.some((option) => option.checked);
  };
  trigger.addEventListener("click", () => {
    if (!picker.hidden) {
      options.forEach((option) => {
        option.checked = !document.querySelector(
          `#protocol-${option.dataset.protocolTarget}`,
        ).hidden;
      });
      updatePicker();
    }
  });
  options.forEach((option) => option.addEventListener("change", updatePicker));
  confirm.addEventListener("click", () => {
    options
      .filter((option) => option.checked)
      .forEach((option) => {
        const target = document.querySelector(
          `#protocol-${option.dataset.protocolTarget}`,
        );
        target.hidden = false;
        target.open = true;
      });
    closePicker();
  });
  updatePicker();
}
// Switch credential controls locally and validate the backend's upload size limit.
const auth = document.querySelector('[name="auth_method"]');
if (auth) {
  const form = auth.form,
    key = form.querySelector(".ssh-private-key"),
    secret = form.querySelector('[name="secret"]');
  const update = () => {
    const isKey = auth.value === "key";
    key.hidden = !isKey;
    secret.closest(".field").hidden = isKey;
    // Execution settings apply equally to password and private-key authentication.
    form.querySelector(".ssh-common-settings").hidden = false;
  };
  auth.addEventListener("change", update);
  update();
  form.querySelector('[name="key_file"]').addEventListener("change", (e) => {
    const file = e.target.files[0];
    e.target.setCustomValidity(
      file && file.size > 65536 ? "NMS accepts private keys up to 64 KB." : "",
    );
    form.querySelector(".selected-file").textContent = file ? file.name : "";
  });
}
// Serial sections retain real endpoint/profile settings; physical controls apply only to direct ports.
const serialData = document.querySelector("#serial-data");
if (serialData && !document.querySelector("#protocol-defaults")) {
  const connections = JSON.parse(serialData.textContent);
  const selector = document.querySelector('[name="connection_id"]');
  const scope = document.querySelector(".serial-communication");
  const setValue = (name, value) => {
    const field = scope.querySelector(`[name="${name}"]`);
    if (field) field.value = value;
  };
  const protocolFields = scope.querySelectorAll('[name="serial_protocol"]');
  const updateSerialProtocol = (changeDefaults = false) => {
    const ascii =
      scope.querySelector('[name="serial_protocol"]:checked')?.value ===
      "modbus_ascii";
    scope.querySelector(".serial-parameter-heading").textContent = ascii
      ? "Modbus ASCII Parameters"
      : "Modbus RTU Parameters";
    const bits = scope.querySelector('[name="data_bits"]');
    bits.querySelector('option[value="7"]').disabled = !ascii;
    if (bits.value && (changeDefaults || (!ascii && bits.value === "7")))
      bits.value = ascii ? "7" : "8";
    scope
      .querySelector('.serial-modbus [name="device_address"]')
      .closest(".field").hidden = false;
  };
  protocolFields.forEach((field) =>
    field.addEventListener("change", () => updateSerialProtocol(true)),
  );
  const updateSerialInterface = () => {
    const flow = scope.querySelector('[name="flow_control"]');
    const selectedInterface = scope.querySelector('[name="serial_interface"]:checked')?.value;
    const row = connections.find(c => String(c.id) === selector.value);
    const direct = row?.transport === "direct";
    const physicalSelected = ["rs232", "rs485"].includes(selectedInterface);
    for (const name of ["baud_rate", "data_bits", "parity", "stop_bits", "flow_control"]) {
      scope.querySelector(`[name="${name}"]`).disabled = !direct || !physicalSelected;
    }
    scope.closest("form").querySelector('button[type="submit"], button:not([type])').disabled = !row || (direct && !physicalSelected);
    const rs485 = selectedInterface === "rs485";
    flow.querySelector('option[value="rtscts"]').disabled = rs485;
    if (rs485 && flow.value === "rtscts") flow.value = "none";
  };
  scope.querySelectorAll('[name="serial_interface"]').forEach(field => field.addEventListener("change", updateSerialInterface));
  const updateSerial = () => {
    const row = connections.find((c) => String(c.id) === selector.value);
    const direct = row?.transport === "direct";
    scope.querySelector(".serial-physical").hidden = false;
    scope.querySelector(".serial-physical-heading").hidden = false;
    scope.querySelector(".serial-physical-note").hidden = !row || direct;
    scope.querySelectorAll('[name="serial_interface"]').forEach((field) => {
      field.disabled = !direct;
      field.required = !!direct;
      field.checked = field.value === row?.settings.interface;
    });
    for (const name of [
      "baud_rate",
      "data_bits",
      "parity",
      "stop_bits",
      "flow_control",
    ]) {
      const field = scope.querySelector(`[name="${name}"]`);
      field.closest(".field").hidden = false;
      field.disabled = !direct;
      setValue(name, direct ? (row.settings[name] ?? "") : "");
    }
    protocolFields.forEach((field) => {
      if (field.value === "modbus_ascii") field.disabled = !direct;
      if (field.value !== "vendor")
        field.checked =
          !!row && field.value === (row.settings.protocol || "modbus_rtu");
    });
    for (const name of ["response_timeout", "serial_retries", "serial_interval", "device_address"]) {
      scope.querySelector(`[name="${name}"]`).disabled = !row;
    }
    scope.closest("form").querySelector('button[type="submit"], button:not([type])').disabled = !row;
    updateSerialProtocol();
    updateSerialInterface();
    setValue("connection_revision", row?.revision || "");
    if (row) {
      setValue("response_timeout", row.settings.timeout_ms / 1000);
      setValue("serial_retries", row.settings.retries);
    }
    const gateway = scope.querySelector(".serial-gateway");
    gateway.hidden = row?.transport !== "rtu_tcp";
    if (!gateway.hidden) {
      const parts = row.endpoint.match(/^\[([^\]]+)\]:([0-9]+)$/);
      setValue("serial_gateway_address", parts?.[1] || "");
      setValue("serial_gateway_port", parts?.[2] || "");
    }
  };
  const savedSerialDraft = scope.closest('.protocol-item').dataset.saved==='1'
    ? [...scope.querySelectorAll('input:not([type=hidden]), select')].map(field=>({field,value:field.value,checked:field.checked})) : [];
  selector.addEventListener("change", updateSerial);
  updateSerial();
  savedSerialDraft.forEach(({field,value,checked})=>{field.value=value;if(field.type==='radio')field.checked=checked;});
  if (savedSerialDraft.length) {updateSerialProtocol();updateSerialInterface();}
}

// Use the URL fragment to switch between protocol and diagnostic wizard steps.
const protocolWorkspace = document.querySelector("#protocol-workspace");
if (protocolWorkspace && !document.querySelector("#device-wizard") && !document.querySelector("#protocol-defaults")) {
  function showProtocolStep() {
    const diagnostics = location.hash === "#diagnostics";
    const graphs = location.hash === "#graphs";
    const dataQuery = location.hash === "#data-query";
    protocolWorkspace.hidden = diagnostics || graphs || dataQuery;
    document.querySelector("#device-data-queries").hidden = !dataQuery;
    document.querySelector("#device-graphs").hidden = !graphs;
    document.querySelector("#diagnostics").hidden = !diagnostics;
    document.querySelectorAll("[data-protocol-step]").forEach((item) => {
      const current =
        Number(item.dataset.protocolStep) ===
        (dataQuery ? 4 : graphs ? 3 : diagnostics ? 2 : 1);
      item.classList.toggle("current", current);
      if (current) item.setAttribute("aria-current", "step");
      else item.removeAttribute("aria-current");
    });
    const previous = document.querySelector("#protocol-previous"),
      next = document.querySelector("#protocol-next");
    if (dataQuery) {
      previous.href = "#graphs";
      next.href = "inventory.php";
      next.textContent = "Done →";
    } else if (graphs) {
      previous.href = "#diagnostics";
      next.href = "#data-query";
      next.textContent = "Next →";
    } else if (diagnostics) {
      previous.href = "#protocol";
      next.href = "#graphs";
      next.textContent = "Next →";
    } else {
      previous.href = document.querySelector(".steps a").href;
      next.href = "#diagnostics";
      next.textContent = "Next →";
    }
  }
  window.addEventListener("hashchange", showProtocolStep);
  showProtocolStep();
}

// Credential visibility is an explicit, local display choice.
document.querySelectorAll('input[type="password"]').forEach((input) => {
  if (input.disabled || input.closest("fieldset[disabled]")) return;
  const wrapper = document.createElement("span");
  wrapper.className = "password-wrap";
  input.replaceWith(wrapper);
  wrapper.append(input);
  const toggle = document.createElement("button");
  toggle.type = "button";
  toggle.className = "password-toggle";
  toggle.textContent = "◉";
  toggle.setAttribute("aria-label", `Show ${input.name.replaceAll("_", " ")}`);
  toggle.addEventListener("click", () => {
    const show = input.type === "password";
    input.type = show ? "text" : "password";
    toggle.setAttribute("aria-pressed", String(show));
    toggle.setAttribute(
      "aria-label",
      `${show ? "Hide" : "Show"} ${input.name.replaceAll("_", " ")}`,
    );
  });
  wrapper.append(toggle);
});

// Keep row actions visible inside the viewport even when the table scrolls.
const rowMenus = Array.from(document.querySelectorAll(".row-menu"));
function closeRowMenus(except = null) {
  rowMenus.forEach((menu) => {
    if (menu !== except) menu.open = false;
  });
}
rowMenus.forEach((menu) => {
  menu.addEventListener("toggle", () => {
    const trigger = menu.querySelector("summary");
    trigger.setAttribute("aria-expanded", String(menu.open));
    if (!menu.open) return;
    closeRowMenus(menu);
    const panel = menu.querySelector(".row-menu-panel");
    const anchor = trigger.getBoundingClientRect();
    const left = Math.max(
      12,
      Math.min(
        anchor.right - panel.offsetWidth,
        innerWidth - panel.offsetWidth - 12,
      ),
    );
    const top = Math.max(
      12,
      Math.min(anchor.bottom + 4, innerHeight - panel.offsetHeight - 12),
    );
    panel.style.left = `${left}px`;
    panel.style.top = `${top}px`;
  });
});
document.addEventListener("click", (event) => {
  if (!event.target.closest(".row-menu")) closeRowMenus();
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    const open = rowMenus.find((menu) => menu.open);
    closeRowMenus();
    if (open) open.querySelector("summary").focus();
  }
});
window.addEventListener("resize", () => closeRowMenus());
document.addEventListener(
  "scroll",
  (event) => {
    if (!event.target.closest?.(".row-menu-panel")) closeRowMenus();
  },
  true,
);

// Hover and focus disclose navigation; clicking the icon has no navigation action.
const headerMenu = document.querySelector(".header-menu");
if (headerMenu) {
  const trigger = headerMenu.querySelector("button");
  const update = () => {
    const open = headerMenu.matches(":hover") || headerMenu.contains(document.activeElement);
    trigger.setAttribute("aria-expanded", String(open));
    document.body.classList.toggle("navigation-menu-open", open);
  };
  ["mouseenter", "mouseleave", "focusin"].forEach(event =>
    headerMenu.addEventListener(event, update),
  );
  // Focus moves after focusout fires; read the final focused element.
  headerMenu.addEventListener("focusout", () => Promise.resolve().then(update));
  update();
}

// Auto-generate while the user has not supplied a manual short name.
const deviceName = document.querySelector('#device-form [name="description"]');
const shortName = document.querySelector('#device-form [name="short_name"]');
if (deviceName && shortName && !shortName.disabled) {
  let automatic = shortName.dataset.autoShort === "1";
  const generate = () => {
    const words = deviceName.value.match(/[A-Za-z0-9]+/g) || [];
    return words.length
      ? (words.length === 1 ? words[0] : words.map((word) => word[0]).join(""))
          .slice(0, 8)
          .toUpperCase()
      : deviceName.value.trim()
        ? "DEVICE"
        : "";
  };
  const update = () => {
    if (automatic) shortName.value = generate();
  };
  deviceName.addEventListener("input", update);
  shortName.addEventListener("input", () => {
    automatic = shortName.value.trim() === "";
  });
  shortName.addEventListener("blur", update);
  update();
}

// Search the installed core templates while the native select remains the submitted value.
document
  .querySelectorAll(
    '#device-form [name="host_template_id"], [data-searchable-template]',
  )
  .forEach((templateSelect, templateIndex) => {
    const templateLabel =
      templateSelect.dataset.searchableTemplate || "Device Template";
    const pluralLabel = templateLabel === "Data Query" ? "Data Queries" : `${templateLabel}s`;
    const optionsId = `template-search-options-${templateIndex}`;
    const field = templateSelect.closest(".field");
    const wrap = templateSelect.closest(".select-wrap");
    const trigger = document.createElement("button");
    trigger.type = "button";
    trigger.className = "template-select-trigger";
    trigger.setAttribute("role", "combobox");
    trigger.setAttribute("aria-label", templateLabel);
    trigger.setAttribute("aria-expanded", "false");
    trigger.setAttribute("aria-controls", optionsId);
    trigger.setAttribute("aria-haspopup", "listbox");
    trigger.disabled = templateSelect.matches(":disabled");
    trigger.textContent =
      templateSelect.selectedOptions[0]?.textContent || "None";
    const panel = document.createElement("div");
    panel.className = "template-search-dropdown";
    // Older RHEL browsers may not implement the Popover API or its CSS selector.
    let usePopover = typeof panel.showPopover === "function" && typeof panel.hidePopover === "function";
    let templatesOpen = false;
    panel.hidden = true;
    if (usePopover) panel.setAttribute("popover", "manual");
    const search = document.createElement("input");
    search.type = "search";
    search.placeholder = `Search ${pluralLabel.toLowerCase()}`;
    search.setAttribute("aria-label", search.placeholder);
    search.autocomplete = "off";
    const list = document.createElement("div");
    list.id = optionsId;
    list.className = "template-search-options";
    list.setAttribute("role", "listbox");
    list.setAttribute("aria-label", pluralLabel);
    const empty = document.createElement("p");
    empty.textContent = templateLabel === "Data Query" ? "No matching data queries" : "No matching templates";
    empty.setAttribute("role", "status");
    empty.hidden = true;
    panel.append(search, list, empty);
    document.body.append(panel);
    wrap.hidden = true;
    field.append(trigger);
    function closeTemplates(restoreFocus = false) {
      if (!templatesOpen) return;
      if (usePopover) panel.hidePopover();
      templatesOpen = false;
      panel.classList.remove("is-open");
      panel.hidden = true;
      trigger.setAttribute("aria-expanded", "false");
      if (restoreFocus) trigger.focus();
    }
    function renderTemplates() {
      list.replaceChildren();
      const query = search.value.trim().toLowerCase();
      Array.from(templateSelect.options)
        .filter((option) => option.textContent.toLowerCase().includes(query))
        .forEach((option) => {
          const choice = document.createElement("button");
          choice.type = "button";
          choice.setAttribute("role", "option");
          choice.setAttribute("aria-selected", String(option.selected));
          choice.textContent = option.textContent;
          choice.disabled = option.disabled;
          choice.addEventListener("click", () => {
            templateSelect.value = option.value;
            templateSelect.dispatchEvent(
              new Event("change", { bubbles: true }),
            );
            trigger.textContent = option.textContent;
            closeTemplates(true);
          });
          list.append(choice);
        });
      empty.hidden = list.children.length !== 0;
    }
    trigger.addEventListener("click", (event) => {
      event.preventDefault();
      if (templatesOpen) {
        closeTemplates();
        return;
      }
      search.value = "";
      renderTemplates();
      const rect = trigger.getBoundingClientRect();
      const height = Math.min(
        300,
        Math.max(rect.top - 12, innerHeight - rect.bottom - 12),
      );
      const width = Math.min(Math.max(rect.width, 280), innerWidth - 24);
      panel.style.width = `${width}px`;
      panel.style.left = `${Math.max(12, Math.min(rect.left, innerWidth - width - 12))}px`;
      panel.style.maxHeight = `${height}px`;
      panel.style.top = `${innerHeight - rect.bottom >= height + 8 ? rect.bottom + 4 : Math.max(8, rect.top - height - 4)}px`;
      panel.hidden = false;
      panel.classList.add("is-open");
      if (usePopover) {
        try { panel.showPopover(); }
        catch (_) { usePopover = false; panel.removeAttribute("popover"); }
      }
      templatesOpen = true;
      trigger.setAttribute("aria-expanded", "true");
      search.focus();
    });
    search.addEventListener("input", renderTemplates);
    panel.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        closeTemplates(true);
      }
      if (event.key === "Enter" && event.target === search) {
        event.preventDefault();
        list.querySelector("button:not(:disabled)")?.click();
      }
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        const options = Array.from(
          list.querySelectorAll("button:not(:disabled)"),
        );
        const index = options.indexOf(document.activeElement);
        options[
          Math.max(
            0,
            Math.min(
              options.length - 1,
              index + (event.key === "ArrowDown" ? 1 : -1),
            ),
          )
        ]?.focus();
      }
    });
    document.addEventListener("click", (event) => {
      if (!panel.contains(event.target) && !trigger.contains(event.target))
        closeTemplates();
    });
    field
      .closest(".device-fields, .graphs-page")
      ?.addEventListener("scroll", () => closeTemplates());
    window.addEventListener("resize", () => closeTemplates());
  });

// Saved presets populate device type choices for the selected segment.
const typeProfileData = document.querySelector('#device-type-profiles');
if (typeProfileData) {
  const profiles = JSON.parse(typeProfileData.textContent);
  const segment = document.querySelector('#device-form [name="category_id"]');
  const type = document.querySelector('#device-form [name="device_type"]');
  const updateTypes = () => {
    const saved = type.value;
    type.replaceChildren(new Option('None',''));
    for (const profile of profiles.filter(p => String(p.category_id) === segment.value)) type.add(new Option(profile.name,profile.name));
    if (saved && [...type.options].some(option => option.value === saved)) type.value = saved;
    else if (type.options.length === 2) type.selectedIndex = 1;
  };
  segment.addEventListener('change',updateTypes);
  // Server-rendered choices preserve the saved selection on first render.
}

// A changed endpoint cannot keep auto-observed identity belonging to the previous IP.
const identityEndpoint = document.querySelector("#identity-endpoint");
if (identityEndpoint) {
  const endpoint = JSON.parse(identityEndpoint.textContent);
  const hostname = document.querySelector('#device-form [name="hostname"]');
  const fields = Array.from(
    document.querySelectorAll('[data-identity-auto="1"]'),
  );
  fields.forEach((field) =>
    field.addEventListener("input", () => {
      field.dataset.identityAuto = "0";
    }),
  );
  hostname.addEventListener("input", () => {
    if (hostname.value.trim() !== endpoint)
      fields.forEach((field) => {
        if (field.dataset.identityAuto === "1") field.value = "";
      });
  });
}

// One floating tooltip layer keeps help visible outside scrolling form panels.
if (document.body) {
  const fieldHelp = {
    description: "Name used to identify this device in inventory.",
    short_name:
      "Created automatically from the device name. You can enter your own short name, up to 8 characters.",
    hostname:
      "Hostname or IP address used by the configured protocols to contact this device.",
    category_id: "Choose the segment to populate the associated device type.",
    device_type:
      "Populated from the selected segment and its saved classification.",
    site_id: "Site where this device is installed.",
    rack_id: "Rack containing this device.",
    rack_position: "Starting rack unit occupied by this device.",
    mac_address:
      "Populates from configured SNMP when reported. A manual value takes precedence.",
    serial_number:
      "Populates from configured SNMP when reported. A manual value takes precedence.",
    chassis_id:
      "Populates from configured SNMP when reported. A manual value takes precedence.",
    host_template_id:
      "Search and select the device template used for this device.",
    poller_id: "Collector responsible for polling this device.",
    device_threads: "Number of collection threads allocated to this device.",
    cross_launch_url:
      "Saved HTTP or HTTPS address opened by the Cross Launch URL action.",
    notes: "Optional notes describing this device.",
    enabled:
      "Enable polling for this device, or disable it while retaining its saved configuration.",
    mode: "Collect neighbours using SNMP.",
    interval_seconds: "Use a multiple of the poller interval.",
    stale_seconds: "At least twice the collection interval.",
    refresh_seconds: "Refresh displayed discovery data.",
    snmp_version: "Choose the version supported by the device.",
    snmp_security_level: "V3: no security, authentication only, or authentication and encryption.",
    connection_id: "Choose a saved endpoint on this collector.",
    serial_interface: "Direct ports: RS-232 or RS-485. TCP gateways manage this setting.",
    serial_protocol: "RTU or ASCII; TCP gateways support RTU only.",
    baud_rate: "Direct-port speed: 300–230400 baud; match the device.",
    data_bits: "RTU: 8 bits. ASCII: 7–8 bits.",
    parity: "Match the device: no parity, Even, Odd, Mark or Space.",
    stop_bits: "1–2 stop bits; match the device.",
    flow_control: "RS-485: None. RS-232: None or RTS/CTS.",
    response_timeout: "Wait for the serial response.",
    serial_retries: "Retry failed serial reads.",
    serial_interval: "Time between serial polls.",
    device_address: "Modbus unit address.",
    serial_gateway_address: "Address from the selected TCP gateway; read-only.",
    serial_gateway_port: "Gateway port: 1–65535; read-only.",
    port: "SSH server port.",
    connect_timeout: "Wait for the SSH connection.",
    command_timeout: "Wait for an SSH command.",
    retries: "Retry failed SSH connections.",
    keepalive: "SSH keepalive interval; 0 disables it.",
    auth_method: "Use a password or private key.",
    username: "SSH account on the device.",
    secret: "Blank keeps the saved password.",
    passphrase: "Private-key passphrase; blank keeps the saved value.",
    snmp_auth_protocol: "SNMP V3 authentication algorithm.",
    snmp_username: "SNMP V3 account name.",
    snmp_password: "At least 8 characters when authentication is enabled; blank keeps saved value.",
    snmp_password_confirm: "Repeat the new authentication password.",
    snmp_priv_protocol: "SNMP V3 encryption algorithm.",
    snmp_priv_passphrase: "At least 8 characters when privacy is enabled; blank keeps saved value.",
    snmp_priv_confirm: "Repeat the new privacy passphrase.",
    snmp_context: "SNMP V3 context; leave blank for the default.",
    snmp_community: "Match the device’s SNMP V1/V2 community.",
    snmp_port: "SNMP UDP port.",
    snmp_timeout: "Wait for an SNMP response.",
    max_oids: "OIDs per SNMP request; choose a listed value.",
    availability_method: "How device reachability is checked.",
    ping_method: "Probe used for reachability checks.",
    ping_timeout: "Wait for a reachability response.",
    ping_retries: "Retry failed reachability probes.",
    ping_count: "Packets per ping test.",
    trace_hops: "Maximum traceroute hops.",
    bandwidth_seconds: "Bandwidth-test duration.",
  };
  document.querySelectorAll(".field-label, .radio-group legend").forEach((label) => {
    const field = label
      .closest(".field, .enable-field, .radio-group")
      ?.querySelector("input, select, textarea");
    let help = fieldHelp[field?.name] || "";
    if (field?.type === "number" && !field.readOnly) {
      const min = field.getAttribute("min"), max = field.getAttribute("max");
      const caption = label.textContent.trim();
      const unit = /\(ms\)/i.test(caption) ? " ms" : /\(sec(?:onds)?\)/i.test(caption) ? " sec" : "";
      if (min !== null || max !== null) {
        const range = min !== null && max !== null ? `Range: ${min}–${max}${unit}.` : min !== null ? `Minimum: ${min}${unit}.` : `Max: ${max}${unit}.`;
        help = `${help} ${range}`.trim();
      }
    }
    if (field?.name === "max_oids") {
      const values = Array.from(field.options).map(option=>Number(option.value)).filter(value=>Number.isFinite(value) && value>0);
      if (values.length) help += ` Range: ${Math.min(...values)}–${Math.max(...values)}.`;
    }
    if (!help || label.querySelector(".field-info")) return;
    if (!field.hasAttribute("aria-label") && !["checkbox", "radio"].includes(field.type))
      field.setAttribute("aria-label", label.textContent.trim());
    const icon = document.createElement("span");
    icon.className = "field-info";
    icon.tabIndex = 0;
    icon.setAttribute("role", "img");
    icon.setAttribute(
      "aria-label",
      `Information about ${label.textContent.trim()}`,
    );
    icon.dataset.tooltip = help;
    icon.innerHTML =
      '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg>';
    // A help icon inside a label must not toggle or focus the labelled form control.
    icon.addEventListener("click", (event) => event.preventDefault());
    label.append(icon);
  });
  document
    .querySelectorAll(
      ".icon-button, .export-button, .row-actions [aria-label], .delete-protocol, .password-toggle, #page-previous, #page-next, [data-close-dialog]",
    )
    .forEach((icon) => {
      if (icon.matches(".header-menu > button")) return;
      const text = icon.getAttribute("aria-label");
      if (text) icon.dataset.tooltip = text;
    });
  // Give each diagnostic option an explicit help target without changing its checkbox.
  document
    .querySelectorAll(".diagnostic-methods .check-row[data-tooltip]")
    .forEach((label) => {
      const icon = document.createElement("span");
      icon.className = "field-info";
      icon.tabIndex = 0;
      icon.setAttribute("role", "img");
      icon.setAttribute(
        "aria-label",
        `Information about ${label.textContent.trim()}`,
      );
      icon.dataset.tooltip = label.dataset.tooltip;
      icon.innerHTML =
        '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg>';
      icon.addEventListener("click", (event) => event.preventDefault());
      label.append(icon);
      label.removeAttribute("data-tooltip");
    });
  const tooltip = document.createElement("div");
  tooltip.className = "help-tooltip";
  tooltip.id = "icct-help-tooltip";
  tooltip.setAttribute("role", "tooltip");
  tooltip.hidden = true;
  document.body.append(tooltip);
  let activeHelp = null;
  const hideHelp = () => {
    if (activeHelp) activeHelp.removeAttribute("aria-describedby");
    activeHelp = null;
    tooltip.hidden = true;
  };
  const showHelp = (target) => {
    hideHelp();
    activeHelp = target;
    tooltip.textContent = target.classList.contains("field-info")
      ? target.dataset.tooltip
      : target.getAttribute("aria-label") || target.dataset.tooltip;
    tooltip.hidden = false;
    target.setAttribute("aria-describedby", tooltip.id);
    const box = target.getBoundingClientRect();
    const size = tooltip.getBoundingClientRect();
    tooltip.style.left = `${Math.max(8, Math.min(box.left + box.width / 2 - size.width / 2, window.innerWidth - size.width - 8))}px`;
    const above = box.top - size.height - 8;
    tooltip.style.top = `${above >= 8 ? above : Math.min(box.bottom + 8, window.innerHeight - size.height - 8)}px`;
  };
  document.addEventListener("mouseover", (event) => {
    const target = event.target.closest("[data-tooltip]");
    if (target && target !== activeHelp) showHelp(target);
  });
  document.addEventListener("mouseout", (event) => {
    if (activeHelp && !activeHelp.contains(event.relatedTarget)) hideHelp();
  });
  document.addEventListener("focusin", (event) => {
    const target = event.target.closest("[data-tooltip]");
    if (target) showHelp(target);
    else hideHelp();
  });
  document.addEventListener("focusout", hideHelp);
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") hideHelp();
  });
  document.addEventListener("scroll", hideHelp, true);
  window.addEventListener("resize", hideHelp);
}

// Match the installed device form: SNMP checks retain timeout/retry settings,
// while only checks that include ping expose the ping method selector.
const availabilityControl = document.querySelector(
  '[name="availability_method"]',
);
if (availabilityControl) {
  const updateAvailabilityFields = () => {
    const method = availabilityControl.value;
    const usesPing = ["1", "3", "4"].includes(method);
    for (const name of ["ping_method", "ping_timeout", "ping_retries"]) {
      const control = document.querySelector(`[name="${name}"]`);
      if (!control) continue;
      const visible = name === "ping_method" ? usesPing : method !== "0";
      control.closest(".field").hidden = !visible;
      // Omit hidden controls rather than replacing their saved settings.
      control.disabled = !visible || !!control.closest("fieldset[disabled]");
    }
  };
  availabilityControl.addEventListener("change", updateAvailabilityFields);
  updateAvailabilityFields();
}

// Persisted per-device enable controls keep parameters visible but inactive.
const protocolStatesNode = document.querySelector("#protocol-states");
if (protocolStatesNode) {
  const states = JSON.parse(protocolStatesNode.textContent);
  Object.entries(states).forEach(([protocol, enabled]) => {
    const section = document.querySelector(`#protocol-${protocol}`);
    if (!section) return;
    const summary = section.querySelector("summary");
    const label = document.createElement("label");
    label.className = "protocol-enable";
    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.checked = enabled;
    checkbox.setAttribute(
      "aria-label",
      `Enable ${protocol.toUpperCase()} protocol`,
    );
    checkbox.dataset.tooltip = `Enable or disable ${protocol.toUpperCase()} for this device`;
    label.append(checkbox);
    summary.insertBefore(label, summary.querySelector(".delete-protocol"));
    const content = section.querySelector(".protocol-content");
    const fields = document.createElement("fieldset");
    fields.className = "protocol-parameters";
    fields.disabled = !enabled;
    while (content.firstChild) fields.append(content.firstChild);
    content.append(fields);
    section.classList.toggle("protocol-disabled", !enabled);
    section.dataset.enabled = enabled ? "1" : "0";
    label.addEventListener("click", (event) => event.stopPropagation());
    checkbox.addEventListener("change", async () => {
      const next = checkbox.checked;
      if (document.querySelector("#device-wizard") || section.dataset.saved !== "1") {
        fields.disabled = !next;
        section.classList.toggle("protocol-disabled", !next);
        // Unsaved panels may be enabled locally, but cannot create a persisted disabled assignment.
        return;
      }
      checkbox.disabled = true;
      const accepted = await icctShowMessage({
        title: next ? "Enable protocol" : "Disable protocol",
        text: `${next ? "Enable" : "Disable"} ${protocol.toUpperCase()} for this device? Saved parameters will be retained.`,
        confirm: true,
        accept: next ? "Enable" : "Disable",
      });
      if (!accepted) {
        checkbox.checked = section.dataset.enabled === "1";
        checkbox.disabled = false;
        return;
      }
      const form = document.querySelector("#toggle-protocol-form");
      if (form.dataset.staticPreview) {
        fields.disabled = !next;
        section.classList.toggle("protocol-disabled", !next);
        section.dataset.enabled = next ? "1" : "0";
        checkbox.disabled = false;
      }
      form.elements.protocol.value = protocol;
      form.elements.enabled.value = next ? "1" : "0";
      form.requestSubmit();
    });
  });
}

// Show only parameters used by the selected native diagnostics, preserving hidden values.
const diagnosticSettings = document.querySelector(".diagnostics-settings");
if (diagnosticSettings) {
  const dependencies = {
    ping_count: ["ping", "arp", "mtr_icmp", "mtr_tcp"],
    trace_hops: [
      "traceroute",
      "traceroute_icmp",
      "traceroute_tcp",
      "mtr_icmp",
      "mtr_tcp",
      "pathchar",
    ],
    bandwidth_seconds: ["iperf3", "netperf"],
  };
  const traceGroup = diagnosticSettings.querySelector(
    "[data-traceroute-group]",
  );
  const traceMethods = [
    ...diagnosticSettings.querySelectorAll('input[name="diagnostic_tools[]"]'),
  ].filter((input) => input.value.startsWith("traceroute"));
  traceGroup?.addEventListener("change", () => {
    traceMethods.forEach((input) => {
      input.checked = traceGroup.checked;
    });
  });
  const mtrGroup = diagnosticSettings.querySelector("[data-mtr-group]");
  const mtrMethods = [
    ...diagnosticSettings.querySelectorAll('input[name="diagnostic_tools[]"]'),
  ].filter((input) => input.value.startsWith("mtr_"));
  mtrGroup?.addEventListener("change", () => {
    mtrMethods.forEach((input) => {
      input.checked = mtrGroup.checked;
    });
  });
  const updateDiagnostics = () => {
    if (mtrGroup) {
      const checked = mtrMethods.filter((input) => input.checked).length;
      mtrGroup.checked = checked === mtrMethods.length && checked > 0;
      mtrGroup.indeterminate = checked > 0 && checked < mtrMethods.length;
    }
    if (traceGroup) {
      const checked = traceMethods.filter((input) => input.checked).length;
      traceGroup.checked = checked === traceMethods.length && checked > 0;
      traceGroup.indeterminate = checked > 0 && checked < traceMethods.length;
    }
    const selected = [
      ...diagnosticSettings.querySelectorAll("input[type=checkbox]:checked"),
    ].map((input) => input.value);
    Object.entries(dependencies).forEach(([name, methods]) => {
      const section = diagnosticSettings.querySelector(
        `[data-diagnostic-parameter="${name}"]`,
      );
      const input = section.querySelector("input");
      const inactive = !methods.some((method) => selected.includes(method));
      input.disabled = inactive;
      section.classList.toggle("diagnostic-inactive", inactive);
      // Disabled fields still retain their configured value when other methods are saved.
      let retained = section.querySelector("input[type=hidden]");
      if (!retained) {
        retained = document.createElement("input");
        retained.type = "hidden";
        retained.name = name;
        section.append(retained);
      }
      retained.value = input.value;
      retained.disabled = !inactive;
    });
  };
  diagnosticSettings.addEventListener("change", updateDiagnostics);
  updateDiagnostics();
}

// Confirm the clone action before opening its explicitly unsaved review form.
document.querySelectorAll("[data-clone-device]").forEach((link) => {
  link.addEventListener("click", async (event) => {
    event.preventDefault();
    const accepted = await icctShowMessage({
      title: "Clone Device",
      text: `Clone ${link.dataset.deviceName}? Review the copied settings and enter a new hostname/IP address before creating the device.`,
      confirm: true,
      accept: "Continue",
    });
    if (accepted) location.href = link.href;
  });
});

// Immediate diagnostics stay in the inventory popup; text is never interpreted as HTML.
const diagnosticDialog = document.querySelector("#diagnostic-dialog");
if (diagnosticDialog) {
  const requestForm = document.querySelector("#instant-diagnostic-request");
  const status = document.querySelector("#diagnostic-status");
  const output = document.querySelector("#diagnostic-output");
  let diagnosticRunning = false;
  diagnosticDialog
    .querySelectorAll("[data-diagnostic-close]")
    .forEach((button) =>
      button.addEventListener("click", () => diagnosticDialog.close()),
    );
  document.querySelectorAll("[data-instant-diagnostic]").forEach((link) => {
    link.addEventListener("click", async (event) => {
      event.preventDefault();
      if (diagnosticRunning) return;
      document.querySelector("#diagnostic-title").textContent =
        `${link.textContent.trim()} — ${link.dataset.deviceName}`;
      output.textContent = "";
      diagnosticDialog.showModal();
      if (requestForm.dataset.staticPreview) {
        status.textContent = "Run diagnostics in the deployed plugin.";
        return;
      }
      diagnosticRunning = true;
      status.textContent = "Running…";
      const data = new FormData(requestForm);
      data.set("host_id", link.dataset.hostId);
      data.set("tool", link.dataset.tool);
      try {
        const response = await fetch(requestForm.getAttribute("action"), {
          method: "POST",
          body: data,
          credentials: "same-origin",
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok)
          throw new Error(payload.error || "Diagnostic could not complete.");
        const result = payload.result;
        status.textContent = result.timed_out
          ? "Timed out"
          : result.exit === 0
            ? "Complete"
            : "Finished with an error";
        // Add the saved inventory name to the target heading, preserving actual IP and hops.
        const commandOutput =
          result.output || "The command returned no output.";
        output.textContent =
          link.dataset.tool === "traceroute"
            ? commandOutput.replace(
                /^traceroute to \S+ (?=\()/m,
                () => `traceroute to ${link.dataset.deviceName} `,
              )
            : commandOutput;
      } catch (error) {
        status.textContent = "Unable to complete diagnostic";
        output.textContent = error.message;
      } finally {
        diagnosticRunning = false;
      }
    });
  });
}

// Removing a graph association follows the same confirmation popup as other plugin actions.
document.querySelectorAll("[data-remove-graph-template]").forEach((form) => {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const accepted = await icctShowMessage({
      title: "Remove Graph Template",
      text: `Remove ${form.dataset.templateName} from this device? Existing graphs and data will remain saved.`,
      confirm: true,
      danger: true,
      accept: "Remove",
    });
    if (!accepted) return;
    if (form.dataset.staticPreview) {
      form.closest(".graph-association, tr").remove();
      icctShowMessage({title: "Graph template removed", text: `${form.dataset.templateName} removed from this preview. Live graphs and data are unchanged.`});
    } else HTMLFormElement.prototype.submit.call(form);
  });
});

// Native query removal uses the shared confirmation dialog.
document.querySelectorAll('[data-remove-data-query]').forEach(form => {
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const accepted = await icctShowMessage({title:'Remove Data Query', text:`Remove ${form.dataset.queryName} from this device? Existing graphs and data sources remain saved.`,confirm:true,danger:true,accept:'Remove'});
    if (!accepted) return;
    if (form.dataset.staticPreview) {
      form.closest('tr').remove();
      icctShowMessage({title:'Data query removed',text:'Association removed from this preview. Live device configuration is unchanged.'});
    } else HTMLFormElement.prototype.submit.call(form);
  });
});
// Re-index method changes are staged by wizard.js; selecting a method never submits or shows save feedback.

// Preserve unsaved panel visibility if a legacy native form returns a validation error.
document.querySelectorAll('.protocol-item form').forEach(form => form.addEventListener('submit', () => {
  form.querySelectorAll('[name="draft_protocols[]"]').forEach(input=>input.remove());
  document.querySelectorAll('.protocol-item:not([hidden])').forEach(panel=>{
    const input=document.createElement('input');input.type='hidden';input.name='draft_protocols[]';input.value=panel.id.replace('protocol-','');form.append(input);
  });
}));

// Graph template selection floats above the existing accordion list.
const graphAddButton = document.querySelector('.add-graph-button');
const graphAddPanel = document.querySelector('#graph-add-panel');
if (graphAddButton && graphAddPanel) {
  const closeGraphAdd = () => { graphAddPanel.hidden = true; graphAddButton.setAttribute('aria-expanded', 'false'); };
  graphAddButton.addEventListener('click', () => {
    graphAddPanel.hidden = !graphAddPanel.hidden;
    graphAddButton.setAttribute('aria-expanded', String(!graphAddPanel.hidden));
  });
  document.addEventListener('click', event => { if (!event.target.closest('.graph-add-control, .template-search-dropdown')) closeGraphAdd(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !graphAddPanel.hidden) { closeGraphAdd(); graphAddButton.focus(); } });
  window.addEventListener('hashchange', closeGraphAdd);
}

// Reserve the real header height when the responsive header wraps.
const applicationHeader = document.querySelector('.topbar');
if (applicationHeader) {
  const reserveHeaderSpace = () => document.body.style.setProperty('--app-header-height', `${applicationHeader.getBoundingClientRect().height}px`);
  reserveHeaderSpace();
  if (typeof ResizeObserver === 'function') new ResizeObserver(reserveHeaderSpace).observe(applicationHeader);
  else window.addEventListener('resize', reserveHeaderSpace);
}

// Presets hold reusable settings; credentials and endpoints are always entered on each device.
const protocolDefaultEditor = document.querySelector('#protocol-defaults');
if (protocolDefaultEditor) {
  protocolDefaultEditor.querySelectorAll('input[type=password], input[type=file], [name=connection_id], [name=device_address]').forEach(field => {
    field.disabled=true;
    field.closest('.field, .ssh-private-key, .upload-zone')?.setAttribute('hidden','');
  });
  protocolDefaultEditor.querySelector('.ssh-private-key')?.setAttribute('hidden','');
}
const protocolDefaultData = document.querySelector('#protocol-default-values');
if (protocolDefaultData) {
  const defaults=JSON.parse(protocolDefaultData.textContent);
  const copied=new Set();
  const newDevice=document.querySelector('#device-wizard')?.dataset.deviceId==='0';
  const copyDefaults = protocol => {
    const section=document.getElementById('protocol-'+protocol);
    if (!section || (section.dataset.saved==='1' && !newDevice) || copied.has(protocol) || !defaults[protocol]) return;
    const form=section.querySelector('form');
    for (const [name,value] of Object.entries(defaults[protocol])) {
      const controls=[...form.elements].filter(field=>field.name===name);
      controls.forEach(field=>{
        if (field.type==='radio') field.checked=String(field.value)===String(value);
        else if (field.type==='checkbox') field.checked=String(value)==='1';
        else field.value=String(value);
      });
    }
    copied.add(protocol);
    form.querySelector('[name=snmp_version]:checked')?.dispatchEvent(new Event('change',{bubbles:true}));
    form.querySelector('[name=auth_method]')?.dispatchEvent(new Event('change',{bubbles:true}));
  };
  if (newDevice) copyDefaults('snmp');
  document.querySelector('#confirm-protocols')?.addEventListener('click',()=>{
    document.querySelectorAll('[data-protocol-target]:checked').forEach(option=>copyDefaults(option.dataset.protocolTarget));
  });
  // Port selection loads collector settings first, then applies defaults to an unsaved serial draft.
  document.querySelector('[name=connection_id]')?.addEventListener('change',()=>{
    const section=document.getElementById('protocol-serial');
    if ((section?.dataset.saved==='1' && !newDevice) || section?.hidden) return;
    copied.delete('serial'); copyDefaults('serial');
    section.querySelector('[name=serial_interface]:checked')?.dispatchEvent(new Event('change',{bubbles:true}));
  });
}
