# Source formatting

First-party source uses four spaces, LF line endings and a final newline. Keep functions,
control blocks and HTML elements on separate lines. Expand long argument lists and nested
arrays; use blank lines to separate validation, data preparation and rendering. Comments
should explain ownership, validation or protocol constraints rather than restate a statement.
Long SQL and text literals may use constant concatenation only when their exact values are
preserved. Keep data URLs, protocol fixtures and whitespace-sensitive payloads intact.

- JavaScript, CSS and JSON: Prettier 3.6.2 with the plugin `.prettierrc.json` and `.prettierignore`.
- Pure PHP services/controllers/tests: Prettier PHP plugin 0.24.0 (PHP 8.0 target),
  followed by PHP-CS-Fixer 3.95.27 with `shared/deployment/php-cs-fixer.php`.
- PHP templates: preserve both PHP scope and HTML nesting. Keep attributes readable and
  place the opening tag closing `>` on the last attribute line, including PHP-generated
  attributes (`bracketSameLine: true`). Preserve literal content inside `textarea`, `pre`,
  `script` and `style`. Format complete PHP fragments independently; do not apply a
  generic HTML formatter or the Prettier PHP plugin to an entire mixed template.
- Python: Black 24.10.0, Python 3.9 target, line length 100.
- Shell: shfmt 3.10.0, four spaces, binary operators at line start, spaced redirections.
- PowerShell: PSScriptAnalyzer 1.23.0 consistent indentation/whitespace and brace rules.
- C#: Roslyn whitespace formatting with four-space block indentation.

Formatting tools are development tools only; no Internet access or formatter is needed on
RHEL to run the plugin. Do not reformat bundled dependencies, generated map/MIB data,
uploads or local configuration. Installer scripts also run their dedicated packaging, lifecycle and offline setup checks.

After formatting, lint the affected languages and run the existing regression suites.
For templates, also verify that PHP tokens/literal values and the rendered HTML element,
attribute and text sequence remain equivalent. Whitespace inside raw-content elements
must remain exact. Review any configuration changes separately from formatting changes.
