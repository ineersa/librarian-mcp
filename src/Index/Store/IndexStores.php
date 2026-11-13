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
    private const int REMOVE_BATCH_SIZE = 100;

    public function __construct(
        public CombinedStore $combined,
        public DocumentIndexer $indexer,
        private ChromaVectorStoreAdapter $vector,
        private SqliteLexicalStoreAdapter $text,
    ) {
    }

    /**
     * @param list<string> $ids
     */
    public function removeChunks(array $ids): void
    {
        foreach (array_chunk($ids, self::REMOVE_BATCH_SIZE) as $batch) {
            $this->combined->remove($batch);
        }
    }

    public function wasTruncated(): bool
    {
        return $this->vector->wasTruncated() || $this->text->wasTruncated();
    }
}
