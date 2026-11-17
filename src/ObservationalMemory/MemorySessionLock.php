<?php

declare(strict_types=1);

namespace App\ObservationalMemory;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Per-session serialization of observe/reflect work (Hatfield serializes per run).
 *
 * A busy session is retried later through Messenger instead of blocking the worker.
 */
final readonly class MemorySessionLock
{
    private const string RESOURCE_PREFIX = 'om_session_';

    /** Covers one bounded job: several chunked model calls of at most 300s each. */
    private const float TTL_SECONDS = 3600.0;

    private const int BUSY_RETRY_DELAY_MS = 30_000;

    public function __construct(
        private LockFactory $lockFactory,
    ) {
    }

    public function acquire(int $sessionPk): SharedLockInterface
    {
        $lock = $this->lockFactory->createLock(self::RESOURCE_PREFIX.$sessionPk, self::TTL_SECONDS);
        if (!$lock->acquire()) {
            throw new RecoverableMessageHandlingException('Memory session is busy.', retryDelay: self::BUSY_RETRY_DELAY_MS);
        }

        return $lock;
    }
}
