<?php

/**
 * Development-only PHP formatting. Runtime hosts do not need PHP-CS-Fixer.
 * Mixed templates preserve HTML/PHP scope indentation and are reviewed separately.
 */
$finder = PhpCsFixer\Finder::create()
    ->in(dirname(__DIR__, 2))
    ->exclude(['protocols/ssh/vendor', 'installation'])
    ->notPath('*/templates/*');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setUsingCache(false)
    ->setRules([
        '@PSR12' => true,
        'array_indentation' => true,
        'whitespace_after_comma_in_array' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'no_multiple_statements_per_line' => true,
        'no_extra_blank_lines' => [
            'tokens' => ['extra', 'comma', 'square_brace_block', 'parenthesis_brace_block'],
        ],
    ])
    ->setFinder($finder);
