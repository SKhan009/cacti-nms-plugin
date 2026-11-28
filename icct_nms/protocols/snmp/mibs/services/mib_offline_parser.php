<?php

/** In-process SMI object reader. No executable, network or writable directory required.
 * Naming, imports, types and table instances follow RFC 2578 / RFC 2579.
 */
final class IcctMibOfflineParser
{
    private array $modules = [];
    private array $oids = [];
    private array $resolving = [];
    public function __construct(array $texts)
    {
        foreach ($texts as $module => $text) {
            try {
                if (self::validateText($text) !== $module) throw new RuntimeException('Module name does not match its dependency identity.');
                $this->modules[$module] = $this->module($text);
            } catch (RuntimeException $e) {
                throw new RuntimeException($module . ': ' . $e->getMessage());
            }
        }
        foreach ($this->modules as $module => $data) {
            foreach ($data['imports'] as $symbol => $owner) {
                if (!isset($this->modules[$owner]['defs'][$symbol])) {
                    throw new RuntimeException($module . ': imported symbol ' . $symbol . ' is not defined in ' . $owner . '. Supply the correct dependency version.');
                }
            }
        }
        foreach ($this->modules as $module => $data) {
            foreach ($data['defs'] as $symbol => $definition) {
                if ($definition['path'] !== null) $this->oid($module, $symbol);
                if ($definition['kind'] === 'OBJECT-TYPE') {
                    $body = $definition['body'];
                    $syntax = $this->clause($body, 'SYNTAX');
                    $access = $this->clause($body, 'MAX-ACCESS') ?: $this->clause($body, 'ACCESS');
                    if (!$syntax || !in_array($access, ['read-only','read-write','read-create','not-accessible','accessible-for-notify','write-only'], true) ||
                        !in_array($this->clause($body, 'STATUS'), ['current','deprecated','obsolete','mandatory','optional'], true)) {
                        throw new RuntimeException($module . '::' . $symbol . ': OBJECT-TYPE requires valid SYNTAX, ACCESS/MAX-ACCESS and STATUS clauses.');
                    }
                    $this->syntax($module, $syntax);
                }
            }
        }
    }

    /** Structural validation is deliberately separate from supported-object interpretation. */
    public static function validateText(string $text): string
    {
        if (!preg_match('//u', $text) || preg_match('/[\x00-\x08\x0b\x0e-\x1f]/', $text)) {
            throw new RuntimeException('Use a text MIB definition, not a binary file.');
        }
        $text = self::clean($text);
        if (!preg_match('/^\s*([A-Za-z][A-Za-z0-9-]*)\s+DEFINITIONS\s*::=\s*BEGIN\b/', $text, $header)) {
            throw new RuntimeException('Expected a MIB module with DEFINITIONS ::= BEGIN, not an HTML page or other document.');
        }
        preg_match_all('/"[^"]*+(?:""[^"]*+)*+"|::=|[A-Za-z][A-Za-z0-9-]*|[^\s]/s', $text, $match);
        $stack = []; $blocks = 0; $closed = false;
        foreach ($match[0] as $token) {
            if ($token === '"') throw new RuntimeException('Unterminated quoted string.');
            if ($closed) throw new RuntimeException('Use one module per file; unexpected text after END.');
            if ($token === 'BEGIN') $blocks++;
            if ($token === 'END') {
                if (--$blocks < 0) throw new RuntimeException('Unexpected END.');
                if ($blocks === 0) $closed = true;
            }
            if (in_array($token, ['{','(','['], true)) $stack[] = $token;
            if (isset(['}'=>'{',')'=>'(',']'=>'['][$token]) && array_pop($stack) !== ['}'=>'{',')'=>'(',']'=>'['][$token]) {
                throw new RuntimeException('Unbalanced definition delimiters.');
            }
        }
        if (!$closed || $blocks !== 0 || $stack) throw new RuntimeException('Incomplete MIB definition: missing END or closing delimiter.');
        return $header[1];
    }
    public static function clean(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        // Comments end at a closing -- or the end of the line; quoted text is preserved.
        $cleaned = preg_replace_callback(
            '/"[^"]*+(?:""[^"]*+)*+"|--[^\r\n]*?(?:--|(?=\r?$))/m',
            fn($m) => str_starts_with($m[0], '--') ? ' ' : $m[0],
            $text,
        );
        // Parser failure must be explicit; never feed missing text into template generation.
        if ($cleaned === null) {
            throw new RuntimeException('MIB comment parsing failed: ' . preg_last_error_msg());
        }
        return $cleaned;
    }
    private function module(string $text): array
    {
        $text = self::clean($text);
        if (!preg_match('/\bEND\s*$/', $text)) {
            throw new RuntimeException('Incomplete MIB module: END is missing.');
        }
        preg_match_all(
            '/"[^"]*+(?:""[^"]*+)*+"|::=|\.\.|[A-Za-z][A-Za-z0-9-]*|-?[0-9]+|[^\s]/s',
            $text,
            $matches,
        );
        $t = $matches[0];
        $imports = [];
        $defs = [];
        $count = count($t);
        if ($count > 250000) {
            throw new RuntimeException('MIB token limit exceeded.');
        }
        for ($i = 4; $i < $count; $i++) {
            if ($t[$i] === 'EXPORTS') {
                while ($i < $count && $t[$i] !== ';') $i++;
                if ($i >= $count) throw new RuntimeException('Incomplete EXPORTS clause.');
                continue;
            }
            if ($t[$i] === 'IMPORTS') {
                $names = [];
                for ($i++; $i < $count && $t[$i] !== ';'; $i++) {
                    if ($t[$i] === 'FROM') {
                        $module = $t[++$i] ?? '';
                        if (!$names || !preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D', $module)) throw new RuntimeException('Invalid IMPORTS clause.');
                        foreach ($names as $name) {
                            if (isset($imports[$name]) && $imports[$name] !== $module) throw new RuntimeException('Conflicting import: ' . $name . '.');
                            $imports[$name] = $module;
                        }
                        $names = [];
                    } elseif (preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D', $t[$i])) {
                        $names[] = $t[$i];
                    }
                }
                if ($i >= $count || $names) throw new RuntimeException('Incomplete IMPORTS clause: each symbol needs FROM and the clause must end with a semicolon.');
                continue;
            }
            if (
                $t[$i] === 'END' && $i === $count - 1
            ) {
                continue;
            }
            $name = $t[$i];
            $kind = $t[$i + 1] ?? '';
            if ($kind === 'MACRO') {
                if (isset($defs[$name])) throw new RuntimeException('Duplicate definition: ' . $name . '.');
                $defs[$name] = ['kind'=>'MACRO', 'body'=>[], 'path'=>null];
                while ($i < $count && $t[$i] !== 'END') {
                    $i++;
                }
                continue;
            }
            $oidKind = in_array(
                $kind,
                [
                    'OBJECT-TYPE',
                    'OBJECT-IDENTITY',
                    'MODULE-IDENTITY',
                    'NOTIFICATION-TYPE',
                    'OBJECT-GROUP',
                    'NOTIFICATION-GROUP',
                    'MODULE-COMPLIANCE',
                    'AGENT-CAPABILITIES',
                    'TRAP-TYPE',
                ],
                true,
            );
            if ($kind === 'OBJECT' && ($t[$i + 2] ?? '') === 'IDENTIFIER') {
                $oidKind = true;
            }
            if ($oidKind) {
                $j = $i + 2;
                $body = [];
                while ($j < $count && $t[$j] !== '::=') {
                    $body[] = $t[$j++];
                }
                if ($j === $count) {
                    throw new RuntimeException('Incomplete definition: ' . $name . '.');
                }
                if (($t[$j + 1] ?? '') === '{') {
                    $j += 2;
                    $path = [];
                    while ($j < $count && $t[$j] !== '}') {
                        $path[] = $t[$j++];
                    }
                    if ($j === $count) {
                        throw new RuntimeException('Incomplete OID: ' . $name . '.');
                    }
                } elseif ($kind === 'TRAP-TYPE') {
                    $enterprise = $this->clause($body, 'ENTERPRISE');
                    $path = [$enterprise, '0', $t[$j + 1] ?? ''];
                    $j++;
                } else {
                    throw new RuntimeException('Unsupported OID assignment: ' . $name . '.');
                }
                if (isset($defs[$name])) {
                    throw new RuntimeException('Duplicate definition: ' . $name . '.');
                }
                $defs[$name] = ['kind' => $kind, 'body' => $body, 'path' => $path];
                $i = $j;
                continue;
            }
            if ($kind === '::=' && ($t[$i + 2] ?? '') !== 'BEGIN') {
                if (isset($defs[$name])) throw new RuntimeException('Duplicate definition: ' . $name . '.');
                $j = $i + 2;
                $body = [];
                $depth = 0;
                for (; $j < $count; $j++) {
                    $v = $t[$j];
                    if (
                        $depth === 0 &&
                        ($v === 'END' ||
                            ($j > $i + 2 &&
                                !in_array(
                                    $v,
                                    [
                                        'SYNTAX',
                                        'STATUS',
                                        'DESCRIPTION',
                                        'REFERENCE',
                                        'DISPLAY-HINT',
                                    ],
                                    true,
                                ) &&
                                preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D', $v) &&
                                in_array(
                                    $t[$j + 1] ?? '',
                                    [
                                        '::=',
                                        'MACRO',
                                        'OBJECT-TYPE',
                                        'OBJECT',
                                        'MODULE-IDENTITY',
                                        'NOTIFICATION-TYPE',
                                        'OBJECT-IDENTITY',
                                        'TRAP-TYPE',
                                        'OBJECT-GROUP',
                                        'NOTIFICATION-GROUP',
                                        'MODULE-COMPLIANCE',
                                        'AGENT-CAPABILITIES',
                                    ],
                                    true,
                                )))
                    ) {
                        break;
                    }
                    $body[] = $v;
                    if (in_array($v, ['{', '(', '['], true)) {
                        $depth++;
                    }
                    if (in_array($v, ['}', ')', ']'], true)) {
                        $depth--;
                    }
                }
                $defs[$name] = ['kind' => 'TYPE', 'body' => $body, 'path' => null];
                $i = $j - 1;
                continue;
            }
            throw new RuntimeException('Unsupported or malformed definition near ' . $name . '.');
        }
        return ['imports' => $imports, 'defs' => $defs];
    }
    private function clause(array $body, string $key): string
    {
        $pos = array_search($key, $body, true);
        if ($pos === false) {
            return '';
        }
        $result = [];
        $depth = 0;
        $stops = [
            'UNITS',
            'MAX-ACCESS',
            'ACCESS',
            'STATUS',
            'DESCRIPTION',
            'REFERENCE',
            'INDEX',
            'AUGMENTS',
            'DEFVAL',
            'DISPLAY-HINT',
            'SYNTAX',
            'OBJECTS',
            'ENTERPRISE',
            'VARIABLES',
        ];
        for ($i = $pos + 1; $i < count($body); $i++) {
            $v = $body[$i];
            if ($depth === 0 && in_array($v, $stops, true)) {
                break;
            }
            $result[] = $v;
            if (in_array($v, ['{', '('], true)) {
                $depth++;
            }
            if (in_array($v, ['}', ')'], true)) {
                $depth--;
            }
            if (str_starts_with($v, '"')) {
                break;
            }
        }
        $s = implode(' ', $result);
        if (str_starts_with($s, '"')) {
            return str_replace('""', '"', substr($s, 1, -1));
        }
        return preg_replace(
            ['/\s*\.\.\s*/', '/\(\s+/', '/\s+\)/', '/\s+,\s*/'],
            ['..', '(', ')', ', '],
            $s,
        );
    }
    private function reference(string $module, string $name): array
    {
        if (isset($this->modules[$module]['defs'][$name])) {
            return [$module, $name];
        }
        $import = $this->modules[$module]['imports'][$name] ?? null;
        if ($import && isset($this->modules[$import]['defs'][$name])) {
            return [$import, $name];
        }
        throw new RuntimeException(
            'Unresolved symbol ' . $module . '::' . $name . '. Upload its defining module.',
        );
    }
    private function oid(string $module, string $name): string
    {
        $roots = [
            'iso' => '1',
            'ccitt' => '0',
            'itu-t' => '0',
            'joint-iso-ccitt' => '2',
            'joint-iso-itu-t' => '2',
        ];
        if (isset($roots[$name])) {
            return $roots[$name];
        }
        [$module, $name] = $this->reference($module, $name);
        $key = $module . '::' . $name;
        if (isset($this->oids[$key])) {
            return $this->oids[$key];
        }
        if (isset($this->resolving[$key])) {
            throw new RuntimeException('Circular OID definition: ' . $key . '.');
        }
        if (count($this->resolving) >= 128) {
            throw new RuntimeException('OID nesting limit exceeded.');
        }
        $this->resolving[$key] = true;
        $path = $this->modules[$module]['defs'][$name]['path'];
        if (!$path) {
            throw new RuntimeException('Missing OID definition: ' . $key . '.');
        }
        $parts = [];
        foreach ($path as $i => $token) {
            if (preg_match('/^[0-9]+$/D', $token)) {
                $parts[] = $token;
                continue;
            }
            if (in_array($token, ['.', ',', '(', ')'], true)) {
                continue;
            }
            if (!preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D', $token)) {
                throw new RuntimeException('Unsupported OID notation: ' . $key . '.');
            }
            if (($path[$i + 1] ?? '') === '(') {
                $number = $path[$i + 2] ?? '';
                if (!preg_match('/^[0-9]+$/D', $number) || ($path[$i + 3] ?? '') !== ')') {
                    throw new RuntimeException('Invalid named OID arc: ' . $key . '.');
                }
                // Resolved by the numeric token on the following pass; parentheses skipped below.
                continue;
            }
            $prefix = explode('.', $this->oid($module, $token));
            if ($parts && array_slice($prefix, 0, count($parts)) !== $parts) {
                throw new RuntimeException('Conflicting OID path: ' . $key . '.');
            }
            $parts = $prefix;
        }
        if (count($parts) > 128 || !$parts || !in_array($parts[0], ['0', '1', '2'], true)) {
            throw new RuntimeException('Invalid OID path: ' . $key . '.');
        }
        foreach ($parts as $arc) {
            if (strlen($arc) > 10 || (float) $arc > 4294967295) {
                throw new RuntimeException('OID arc out of range: ' . $key . '.');
            }
        }
        unset($this->resolving[$key]);
        return $this->oids[$key] = implode('.', $parts);
    }
    private function syntax(string $module, string $syntax, array $seen = []): string
    {
        preg_match('/^([A-Za-z][A-Za-z0-9-]*)/', $syntax, $m);
        $name = $m[1] ?? '';
        $primitive = [
            'INTEGER',
            'Integer32',
            'Unsigned32',
            'Gauge32',
            'Counter32',
            'Counter64',
            'TimeTicks',
            'OCTET',
            'OBJECT',
            'BITS',
            'SEQUENCE',
            'IpAddress',
            'Opaque',
        ];
        $compat = ['Counter' => 'Counter32', 'Gauge' => 'Gauge32'];
        if (isset($compat[$name])) {
            return preg_replace('/^' . preg_quote($name, '/') . '\b/', $compat[$name], $syntax);
        }
        if (in_array($name, $primitive, true)) {
            return $syntax;
        }
        [$owner, $alias] = $this->reference($module, $name);
        $key = $owner . '::' . $alias;
        if (isset($seen[$key])) {
            throw new RuntimeException('Circular syntax: ' . $key . '.');
        }
        $seen[$key] = true;
        $body = $this->modules[$owner]['defs'][$alias]['body'];
        $resolved = in_array('TEXTUAL-CONVENTION', $body, true)
            ? $this->clause($body, 'SYNTAX')
            : implode(' ', $body);
        if (!$resolved) {
            throw new RuntimeException('Unsupported syntax: ' . $key . '.');
        }
        return $this->syntax($owner, $resolved, $seen) . substr($syntax, strlen($name));
    }
    public function records(array $requested, ?callable $progress = null): array
    {
        $records = [];
        $total = 0;
        foreach ($requested as $module) {
            foreach ($this->modules[$module]['defs'] as $def) {
                if (
                    in_array($def['kind'], ['OBJECT-TYPE', 'NOTIFICATION-TYPE', 'TRAP-TYPE'], true)
                ) {
                    $total++;
                }
            }
        }
        if ($progress) {
            $progress(['phase' => 'scan', 'processed' => 0, 'total' => $total]);
        }
        foreach ($requested as $module) {
            foreach ($this->modules[$module]['defs'] as $name => $def) {
                if (
                    !in_array($def['kind'], ['OBJECT-TYPE', 'NOTIFICATION-TYPE', 'TRAP-TYPE'], true)
                ) {
                    continue;
                }
                if (count($records) >= 512) {
                    throw new RuntimeException(
                        'Limit: 512 object definitions per upload. Split larger modules.',
                    );
                }
                $body = $def['body'];
                $oid = $this->oid($module, $name);
                $syntax = $this->clause($body, 'SYNTAX');
                if ($syntax) {
                    $syntax = $this->syntax($module, $syntax);
                }
                $access = $this->clause($body, 'MAX-ACCESS') ?: $this->clause($body, 'ACCESS');
                if ($def['kind'] === 'OBJECT-TYPE' && (!$syntax || !in_array($access,
                    ['read-only','read-write','read-create','not-accessible','accessible-for-notify','write-only'], true) ||
                    !in_array($this->clause($body, 'STATUS'), ['current','deprecated','obsolete','mandatory','optional'], true))) {
                    throw new RuntimeException($module . '::' . $name . ': OBJECT-TYPE requires valid SYNTAX, ACCESS/MAX-ACCESS and STATUS clauses.');
                }
                $table = false;
                $indexDescription = 'OID instance suffix';
                foreach ($this->modules as $owner => $mod) {
                    foreach ($mod['defs'] as $parent => $p) {
                        if (
                            $p['kind'] !== 'OBJECT-TYPE' ||
                            (!in_array('INDEX', $p['body'], true) &&
                                !in_array('AUGMENTS', $p['body'], true))
                        ) {
                            continue;
                        }
                        if ($this->oid($owner, $parent) === preg_replace('/\.[0-9]+$/', '', $oid)) {
                            $table = true;
                            $indexDescription =
                                $this->clause($p['body'], 'INDEX') ?:
                                'Augments ' . $this->clause($p['body'], 'AUGMENTS');
                            break 2;
                        }
                    }
                }
                $numeric =
                    $def['kind'] === 'OBJECT-TYPE' &&
                    in_array($access, ['read-only', 'read-write', 'read-create'], true) &&
                    preg_match(
                        '/^(INTEGER|Integer32|Unsigned32|Gauge32|Counter32|Counter64|TimeTicks)\b/',
                        $syntax,
                    );
                $enum = str_contains($syntax, '{');
                $counter = (bool) preg_match('/^Counter(?:32|64)\b/', $syntax);
                $records[] = [
                    'symbol' => $module . '::' . $name,
                    'kind' => $def['kind'],
                    'base_oid' => $oid,
                    'oid' => $table ? $oid : $oid . '.0',
                    'syntax' => $syntax,
                    'access' => $access,
                    'description' => trim(
                        preg_replace('/\s+/', ' ', $this->clause($body, 'DESCRIPTION')),
                    ),
                    'units' => $this->clause($body, 'UNITS'),
                    'table' => $table,
                    'index_description' => $table ? $indexDescription : 'Scalar instance .0',
                    'numeric' => (bool) $numeric,
                    'enum' => $enum,
                    'ds_type' => $counter ? 2 : 1,
                    'label' => $name,
                ] +
                    icct_mib_bounds($syntax) + [
                        'reason' => !$numeric
                            ? 'Metadata / nonnumeric object'
                            : ($table
                                ? 'Table object'
                                : ($enum
                                    ? 'Numeric enumeration: review state mapping'
                                    : 'Readable numeric scalar')),
                    ];
                if ($progress) {
                    $progress([
                        'phase' => 'scan',
                        'processed' => count($records),
                        'total' => $total,
                        'symbol' => $module . '::' . $name,
                    ]);
                }
            }
        }
        return $records;
    }
}
