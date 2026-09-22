# NMS code style

The goal is readable operational code: a RHEL/Cacti administrator should be able to find the action, its validation and its displayed result without decoding compressed lines.

## PHP

- One declaration, statement or HTML element per line when it improves scanning.
- Use tabs for indentation and a 120-character soft line limit.
- Put spaces inside control-flow parentheses: `if ($ready) {`.
- Use descriptive names such as `$editing_profile`, not `$p` outside short local loops.
- Add a one-line docblock to exported functions and a file-purpose docblock at the top of first-party source files.
- Keep database queries readable with table aliases and one selected field per line when a query is more than one line.
- Escape every rendered string through `nms_h()`.

## Templates

- A page entry template assembles sections only. Put each large section in `templates/<page>/`.
- Do not query the database, call shell commands or change configuration from a template.
- Use a `behavior.php` partial for very small page-only scripts; move reusable behavior to `js/`.

## Comments

Comments explain a safety boundary, a non-obvious Cacti/SNMP detail or the reason for a decision. They do not narrate self-evident code.

Good: `// Link-local IPv6 addresses are meaningful only on the reporting interface.`

Avoid: `// Set the host ID.`
