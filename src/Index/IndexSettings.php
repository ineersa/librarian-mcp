<?php

declare(strict_types=1);

namespace App\Index;

/**
 * Immutable embedding/reranker settings for one Librarian index generation.
 */
final readonly class IndexSettings
{
    public const string QUERY_PREFIX = 'Represent this query for searching relevant code: ';
    public const int NORMALIZATION_VERSION = 1;
    public const int CHUNK_ID_VERSION = 2;
    public const int DEFAULT_DIMENSIONS = 768;

    public function __construct(
        public string $embeddingUrl,
        public string $embeddingModel,
        /** Immutable SHA-256 artifact identity for the embedding weights. */
        public string $embeddingArtifact,
        public string $rerankerUrl,
        public string $rerankerModel,
        public int $dimensions = self::DEFAULT_DIMENSIONS,
    ) {
        if ('' === trim($this->embeddingUrl) || '' === trim($this->embeddingModel) || '' === trim($this->embeddingArtifact)) {
            throw new \InvalidArgumentException('Embedding URL, model, and artifact identity are required.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $this->embeddingArtifact)) {
            throw new \InvalidArgumentException('Embedding artifact must be a 64-character lowercase SHA-256 hex digest.');
        }
        if ('' === trim($this->rerankerUrl) || '' === trim($this->rerankerModel)) {
            throw new \InvalidArgumentException('Reranker URL and model are required.');
        }
        if ($this->dimensions < 1) {
            throw new \InvalidArgumentException('Embedding dimensions must be positive.');
        }
    }

    /**
     * Signature of the stored embedding and chunk-identity format.
     */
    public function signature(): string
    {
        return hash('sha256', json_encode([
            $this->embeddingArtifact,
            $this->dimensions,
            $this->embeddingModel,
            MemoryChunker::CHUNK_BYTES,
            MemoryChunker::OVERLAP_BYTES,
            MemoryChunker::CHUNK_LINES,
            self::NORMALIZATION_VERSION,
            self::CHUNK_ID_VERSION,
            self::QUERY_PREFIX,
        ], \JSON_THROW_ON_ERROR));
    }
}
