<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vera;

use App\Vera\VeraIndexingConfig;
use PHPUnit\Framework\TestCase;

final class VeraIndexingConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new VeraIndexingConfig();
        $this->assertSame([], $config->excludePatterns);
        $this->assertFalse($config->noIgnore);
        $this->assertFalse($config->noDefaultExcludes);
    }

    public function testFromArrayFull(): void
    {
        $data = [
            'excludePatterns' => ['_build/**', '**/*.rst.inc'],
            'noIgnore' => true,
            'noDefaultExcludes' => true,
        ];
        $config = VeraIndexingConfig::fromArray($data);

        $this->assertSame(['_build/**', '**/*.rst.inc'], $config->excludePatterns);
        $this->assertTrue($config->noIgnore);
        $this->assertTrue($config->noDefaultExcludes);
    }

    public function testFromArrayPartial(): void
    {
        $config = VeraIndexingConfig::fromArray(['noIgnore' => true]);

        $this->assertSame([], $config->excludePatterns);
        $this->assertTrue($config->noIgnore);
        $this->assertFalse($config->noDefaultExcludes);
    }

    public function testFromArrayEmpty(): void
    {
        $config = VeraIndexingConfig::fromArray([]);

        $this->assertSame([], $config->excludePatterns);
        $this->assertFalse($config->noIgnore);
        $this->assertFalse($config->noDefaultExcludes);
    }

    public function testToArrayRoundTrip(): void
    {
        $original = new VeraIndexingConfig(
            excludePatterns: ['_build/**'],
            noIgnore: true,
            noDefaultExcludes: false,
        );

        $restored = VeraIndexingConfig::fromArray($original->toArray());

        $this->assertSame($original->excludePatterns, $restored->excludePatterns);
        $this->assertSame($original->noIgnore, $restored->noIgnore);
        $this->assertSame($original->noDefaultExcludes, $restored->noDefaultExcludes);
    }

    public function testIsReadonly(): void
    {
        $config = new VeraIndexingConfig();
        // readonly class — all properties are immutable
        $reflection = new \ReflectionClass($config);
        $this->assertTrue($reflection->isReadOnly());
    }
}
