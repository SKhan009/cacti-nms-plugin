// Run with Node: node tests/theme_colors_test.mjs (no packages, Cacti or VM needed).
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
const cssDir = new URL('../css/', import.meta.url);
const semantic = /(?:\.nms-(?:form-message|config-error|saved|state\b|severity\b|ok\b|health-dot\b|association-state\b|live-status\b|device-glyph\b|summary-(?:total|open|critical|ack|resolved)\b|remove-map\b|delete-x\b|x-action\b|icon-action\b)|\.nms-sidebar-status\s*>\s*span|\.nms-topology-node[^{}]*\si\b|\.nms-map-label\.mapped|\.nms-color-select\s+i\b|\.nms-option-color-swatch\b|\.nms-search-select-trigger\.invalid|\.nms-query-actions\s+\.(?:remove|verbose))/;
let rules = 0;
const files = Object.fromEntries(readdirSync(cssDir).filter(n => n.endsWith('.css')).map(n => [n, readFileSync(new URL(n, cssDir), 'utf8')]));
for (const [name, css] of Object.entries(files)) {
 assert(!css.includes('--nms-green'), `${name}: use separate theme and semantic tokens`);
 for (const [, selector, declarations] of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
  rules++;
  if (semantic.test(selector) || /\.nms-config-notice\b|\.nms-rack-device\.(?:up|down)\b/.test(selector)) continue;
  const body = declarations.replace(/--nms-(?:red|orange|amber|success):[^;]+;/g, '');
  for (const [hex] of body.matchAll(/#[\da-f]{6}\b|#[\da-f]{3}\b/gi)) {
   let c = hex.slice(1); if (c.length === 3) c = [...c].map(x => x + x).join('');
   assert(c.slice(0, 2) === c.slice(2, 4) && c.slice(2, 4) === c.slice(4, 6), `${name}: non-neutral ${hex} in ${selector.trim()}`);
  }
  for (const [, r, g, b] of body.matchAll(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/g)) {
   assert(+r === +g && +g === +b, `${name}: non-neutral RGB in ${selector.trim()}`);
  }
 }
}
assert(files['nms-devices.css'].includes('.nms-form-message.success{background:#eaf6ed;border-color:#bfe0c7;color:#176b37}'));
assert(files['nms-devices.css'].includes('.nms-form-message.error{background:#fff0ee;border-color:#efc5c0;color:#a72c25}'));
assert(files['nms-v1.1.css'].includes('--nms-success:#21854b'));
assert(files['nms-snmp-form.css'].includes('background: #008a6d;'), 'Keep the graph swatch color');
assert(files['nms-topology.css'].includes('.nms-live-status.up{background:#e5f3e8;color:var(--nms-success)}'));
assert(files['nms-layout.css'].includes('repeat(2, minmax(0, 1fr))'), 'Retain two-column layout');
console.log(`PASS: ${Object.keys(files).length} stylesheets, ${rules} rules; neutral theme and semantic colors checked.`);
