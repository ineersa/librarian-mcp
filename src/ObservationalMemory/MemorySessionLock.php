<?php

declare(strict_types=1);

namespace App\ObservationalMemory;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Per-session serialization of observe/reflect work (Hatfield serializes per run).
 *
 * Acquisition is nonblocking so the caller can defer busy sessions.
 */
final readonly class MemorySessionLock
{
    private const string RESOURCE_PREFIX = 'om_session_';

    /** Covers one bounded job: several chunked model calls of at most 300s each. */
    private const float TTL_SECONDS = 3600.0;

    public function __construct(
        private LockFactory $lockFactory,
    ) {
    }

    public function tryAcquire(int $sessionPk): ?SharedLockInterface
    {
        $lock = $this->lockFactory->createLock(self::RESOURCE_PREFIX.$sessionPk, self::TTL_SECONDS);

        return $lock->acquire() ? $lock : null;
    }
}
