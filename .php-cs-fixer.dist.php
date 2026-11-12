<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    // @see https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/pull/7777
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'fopen_flags' => ['b_mode' => true],
        'protected_to_private' => false,
        'declare_strict_types' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],
    ])
    ->setRiskyAllowed(true)
    ->setFinder(PhpCsFixer\Finder::create()
        ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/.castor'])
        ->append([__DIR__.'/bin/console', __DIR__.'/public/index.php', __DIR__.'/castor.php', __DIR__.'/mate/config.php', __FILE__])
    )
;
