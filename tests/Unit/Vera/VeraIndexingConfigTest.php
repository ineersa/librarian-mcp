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
        self::assertSame([], $config->excludePatterns);
        self::assertFalse($config->noDefaultExcludes);
    }

    public function testFromArrayFull(): void
    {
        $data = [
            'excludePatterns' => ['_build/**', '**/*.rst.inc'],
            'noDefaultExcludes' => true,
        ];
        $config = VeraIndexingConfig::fromArray($data);

        self::assertSame(['_build/**', '**/*.rst.inc'], $config->excludePatterns);
        self::assertTrue($config->noDefaultExcludes);
    }

    public function testFromArrayPartial(): void
    {
        $config = VeraIndexingConfig::fromArray(['noDefaultExcludes' => true]);

        self::assertSame([], $config->excludePatterns);
        self::assertTrue($config->noDefaultExcludes);
    }

    public function testFromArrayEmpty(): void
    {
        $config = VeraIndexingConfig::fromArray([]);

        self::assertSame([], $config->excludePatterns);
        self::assertFalse($config->noDefaultExcludes);
    }

    public function testFromArrayIgnoresLegacyNoIgnore(): void
    {
        $config = VeraIndexingConfig::fromArray(['noIgnore' => true]);

        self::assertSame([], $config->excludePatterns);
        self::assertFalse($config->noDefaultExcludes);
    }

    public function testToArrayRoundTrip(): void
    {
        $original = new VeraIndexingConfig(
            excludePatterns: ['_build/**'],
            noDefaultExcludes: false,
        );

        $restored = VeraIndexingConfig::fromArray($original->toArray());

        self::assertSame($original->excludePatterns, $restored->excludePatterns);
        self::assertSame($original->noDefaultExcludes, $restored->noDefaultExcludes);
    }

    public function testIsReadonly(): void
    {
        $config = new VeraIndexingConfig();
        // readonly class — all properties are immutable
        $reflection = new \ReflectionClass($config);
        self::assertTrue($reflection->isReadOnly());
    }
}
