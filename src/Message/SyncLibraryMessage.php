<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when a library sync is requested.
 * The handler is Stage 4's concern — do not create a handler in this stage.
 */
class SyncLibraryMessage
{
    public function __construct(
        public readonly int $libraryId,
    ) {
    }
}
