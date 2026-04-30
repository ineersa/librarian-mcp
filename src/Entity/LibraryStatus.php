<?php

declare(strict_types=1);

namespace App\Entity;

enum LibraryStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Indexing = 'indexing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isQueued(): bool
    {
        return self::Queued === $this;
    }

    public function isIndexing(): bool
    {
        return self::Indexing === $this;
    }
}
