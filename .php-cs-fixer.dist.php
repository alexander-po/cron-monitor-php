<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
    ->append([__FILE__, __DIR__.'/bin/cron-monitor']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP81Migration' => true,
        '@PHP80Migration:risky' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'phpdoc_to_comment' => false,
        'phpdoc_separation' => false,
        // Stay consistent with the cron-monitor host project, which uses
        // descriptive snake_case test method names. PER-CS / @Symfony want
        // camelCase by default, so override explicitly.
        'php_unit_method_casing' => ['case' => 'snake_case'],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache');
