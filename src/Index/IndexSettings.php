<?php

declare(strict_types=1);

namespace App\Index;

/**
 * Immutable embedding identity for Librarian index generations.
 *
 * Endpoints are resolved separately (ModelEndpoints): serving the same artifact from another
 * URL does not change the signature, so switching Built-in and API keeps existing generations.
 */
final readonly class IndexSettings
{
    public const string QUERY_PREFIX = 'Represent this query for searching relevant code: ';
    public const int NORMALIZATION_VERSION = 1;
    public const int CHUNK_ID_VERSION = 3;
    public const int DEFAULT_DIMENSIONS = 768;
    /** Built-in embeddings `-c`/`--ubatch-size`: one whole input must fit. */
    public const int EMBEDDING_CONTEXT_TOKENS = 2048;
    /** Each token covers at least one byte; leaves room for the CLS/SEP markers. */
    public const int EMBEDDING_INPUT_BYTES = self::EMBEDDING_CONTEXT_TOKENS - 8;

    public function __construct(
        /** Model label recorded in the signature; requests use the resolved endpoint's model name. */
        public string $embeddingModel,
        /** Immutable SHA-256 artifact identity for the embedding weights. */
        public string $embeddingArtifact,
        public int $dimensions = self::DEFAULT_DIMENSIONS,
    ) {
        if ('' === trim($this->embeddingModel) || '' === trim($this->embeddingArtifact)) {
            throw new \InvalidArgumentException('Embedding model and artifact identity are required.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $this->embeddingArtifact)) {
            throw new \InvalidArgumentException('Embedding artifact must be a 64-character lowercase SHA-256 hex digest.');
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
