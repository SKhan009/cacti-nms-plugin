# 0.11.2 — Native Cacti pagination

Replaced custom pagination buttons and counters with Cacti core html_nav_bar output throughout the plugin. Single-page lists show the native All N summary. Multi-page lists use native numbered page links and Previous/Next arrows. Existing filtered lists keep their query parameters. Editable tables display core-generated navigation in place so page changes retain all unsaved fields.

Verified the Simulator summary, Discovery page 2 (26–50 of 51), row-count change to 50, preservation of an unsaved interval value, PHP syntax and no browser JavaScript errors. No device, protocol or topology configuration changed.
