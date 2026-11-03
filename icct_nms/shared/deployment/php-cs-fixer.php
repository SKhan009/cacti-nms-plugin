<?php
/** Development-only formatting; excludes bundled dependencies and never runs on page requests. */
$finder = PhpCsFixer\Finder::create()
    ->in(dirname(__DIR__, 2))
    ->exclude('protocols/ssh/vendor')
    ->notPath('shared/deployment/php-cs-fixer.php');
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setUsingCache(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'single_quote' => true,
        'no_multiple_statements_per_line' => true,
    ])
    ->setFinder($finder);
