<?php

declare(strict_types=1);

namespace App\Index\Store;

use Symfony\AI\Store\CombinedStore;
use Symfony\AI\Store\Indexer\DocumentIndexer;

/**
 * Generation-scoped stores for domain indexing and hybrid search.
 */
final readonly class IndexStores
{
    public function __construct(
        public CombinedStore $combined,
        public DocumentIndexer $indexer,
    ) {
    }
}
