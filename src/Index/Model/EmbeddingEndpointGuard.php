<?php

declare(strict_types=1);

namespace App\Index\Model;

use App\Index\IndexSettings;
use App\Index\Store\IndexStoreFactory;
use App\Repository\IndexGenerationRepository;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Query\VectorQuery;

/**
 * Checks that a candidate embeddings endpoint serves the model existing generations were built with.
 *
 * Re-embeds one stored chunk with the candidate and compares it with its stored vector. The same
 * GGUF on another device or backend stays above 0.999 (CPU vs CUDA measured 0.9997); a different
 * model does not share the vector space. Samples any generation holding vectors, including one still
 * being built; only when no generation holds vectors is any model accepted.
 */
final readonly class EmbeddingEndpointGuard
{
    public const float MIN_SIMILARITY = 0.99;
    private const string PROBE_TEXT = 'function handle(Request $request): Response';

    public function __construct(
        private IndexGenerationRepository $generations,
        private IndexStoreFactory $stores,
        private CodeRankVectorizerFactory $vectorizers,
        private IndexSettings $settings,
    ) {
    }

    /** Null when the endpoint is compatible, otherwise the reason it is not. */
    public function incompatibility(ModelEndpoint $candidate): ?string
    {
        $generation = $this->generations->findPopulatedWithSignature($this->settings->signature());
        if (null === $generation) {
            return null;
        }

        $vectorizer = $this->vectorizers->create($candidate);
        try {
            $probe = $vectorizer->vectorize(self::PROBE_TEXT);
        } catch (\Throwable) {
            return \sprintf('The embeddings endpoint did not return a valid %d-dimension vector.', $this->settings->dimensions);
        }

        $store = $this->stores->forGeneration((int) $generation->project->id, (int) $generation->id, initialize: false);
        $stored = null;
        foreach ($store->query(new VectorQuery($probe), ['limit' => 1]) as $document) {
            $stored = $document;
        }
        $text = $stored instanceof VectorDocumentInterface ? $stored->getMetadata()->getText() : null;
        if (null === $text || '' === $text) {
            // The generation holds vectors, so a missing sample is not proof that nothing would mix.
            return 'Could not read a stored chunk to compare the embeddings endpoint with the existing indexes. Try again after indexing finishes.';
        }

        try {
            $fresh = $vectorizer->vectorize($text);
        } catch (\Throwable) {
            return \sprintf('The embeddings endpoint did not return a valid %d-dimension vector.', $this->settings->dimensions);
        }
        $storedVector = $stored->getVector();
        $similarity = $storedVector instanceof Vector ? self::cosine($storedVector->getData(), $fresh->getData()) : 0.0;
        if ($similarity < self::MIN_SIMILARITY) {
            return \sprintf(
                'The embeddings endpoint serves a different model than the existing indexes (similarity %.3f, need %.2f). Serve the same GGUF, or the indexes would mix vectors from two models.',
                $similarity,
                self::MIN_SIMILARITY,
            );
        }

        return null;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function cosine(array $a, array $b): float
    {
        if (\count($a) !== \count($b) || [] === $a) {
            return 0.0;
        }
        $dot = $normA = $normB = 0.0;
        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        return $normA > 0.0 && $normB > 0.0 ? $dot / sqrt($normA * $normB) : 0.0;
    }
}
