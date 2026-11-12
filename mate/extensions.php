<?php

// This file is managed by Mate - use `discover` or `skills:*` commands
// over manual editing. Only changes to `mode` or `enabled` are kept,
// every other key is overwritten by Mate.

return [
    'symfony/ai-mate' => [
        'enabled' => true,
        'skills' => [
            'php-environment-check' => [
                'enabled' => false,
                'mode' => 'managed',
                'state' => 'disabled',
                'source' => 'vendor/symfony/ai-mate/skills/php-environment-check',
                'source_hash' => null,
                'hash' => null,
                'targets' => [],
            ],
            'system-information' => [
                'enabled' => false,
                'mode' => 'managed',
                'state' => 'disabled',
                'source' => 'vendor/symfony/ai-mate/skills/system-information',
                'source_hash' => null,
                'hash' => null,
                'targets' => [],
            ],
        ],
    ],
    'symfony/ai-monolog-mate-extension' => [
        'enabled' => true,
        'skills' => [
            'symfony-log-investigation' => [
                'enabled' => false,
                'mode' => 'managed',
                'state' => 'disabled',
                'source' => 'vendor/symfony/ai-monolog-mate-extension/skills/symfony-log-investigation',
                'source_hash' => null,
                'hash' => null,
                'targets' => [],
            ],
        ],
    ],
    'symfony/ai-symfony-mate-extension' => [
        'enabled' => true,
        'skills' => [
            'symfony-dotenv-diagnostics' => [
                'enabled' => false,
                'mode' => 'managed',
                'state' => 'disabled',
                'source' => 'vendor/symfony/ai-symfony-mate-extension/skills/symfony-dotenv-diagnostics',
                'source_hash' => null,
                'hash' => null,
                'targets' => [],
            ],
            'symfony-profiler-debugging' => [
                'enabled' => false,
                'mode' => 'managed',
                'state' => 'disabled',
                'source' => 'vendor/symfony/ai-symfony-mate-extension/skills/symfony-profiler-debugging',
                'source_hash' => null,
                'hash' => null,
                'targets' => [],
            ],
            'symfony-request-triage' => [
                'enabled' => false,
                'mode' => 'managed',
                'state' => 'disabled',
                'source' => 'vendor/symfony/ai-symfony-mate-extension/skills/symfony-request-triage',
                'source_hash' => null,
                'hash' => null,
                'targets' => [],
            ],
            'symfony-service-inspection' => [
                'enabled' => false,
                'mode' => 'managed',
                'state' => 'disabled',
                'source' => 'vendor/symfony/ai-symfony-mate-extension/skills/symfony-service-inspection',
                'source_hash' => null,
                'hash' => null,
                'targets' => [],
            ],
        ],
    ],
];
