<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;

/**
 * Creates a MockHub that swallows all publishes.
 * Wired in config/services_test.yaml to replace the real Mercure hub.
 */
class NullPublisherFactory
{
    public function createHub(): MockHub
    {
        return new MockHub(
            'https://example.com/.well-known/mercure',
            new StaticTokenProvider('test-token'),
            static fn () => '',
        );
    }
}
