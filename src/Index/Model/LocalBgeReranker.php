<?php

declare(strict_types=1);

namespace App\Index\Model;

use App\Index\IndexException;
use App\Index\IndexSettings;
use Symfony\AI\Platform\Bridge\Voyage\Reranker;
use Symfony\AI\Platform\Bridge\Voyage\Reranker\ModelClient as VoyageRerankerClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Local BGE reranker via Voyage-compatible request client + llama.cpp result validation.
 */
final class LocalBgeReranker
{
    public const int BATCH_SIZE = 8;
    public const int DOCUMENT_CHARACTERS = 768;
    public const float MIN_SCORE = -4.0;

    private readonly VoyageRerankerClient $client;
    private readonly Reranker $model;

    public function __construct(
        private readonly IndexSettings $settings,
        ?HttpClientInterface $httpClient = null,
        ?VoyageRerankerClient $client = null,
    ) {
        $http = $httpClient ?? HttpClient::create([
            'timeout' => 30.0,
            'max_duration' => 30.0,
            'extra' => [
                'trace_content' => false,
            ],
        ]);
        $this->client = $client ?? new VoyageRerankerClient(
            $http,
            '',
            $this->baseUrl($this->settings->rerankerUrl),
        );
        $this->model = new Reranker($this->settings->rerankerModel);
    }

    /**
     * @param list<string> $documents
     *
     * @return list<int> original document offsets, best first (capability / simple API)
     */
    public function rank(string $query, array $documents): array
    {
        return array_column($this->rankWithScores($query, $documents), 'offset');
    }

    /**
     * @param list<string> $documents
     *
     * @return list<array{offset: int, score: float}>
     */
    public function rankWithScores(string $query, array $documents): array
    {
        if ([] === $documents) {
            return [];
        }

        try {
            return $this->rankDocuments($query, $documents);
        } catch (IndexException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw IndexException::sanitized('Configured reranker failed or returned invalid scores.', $error);
        }
    }

    /**
     * @param list<string> $documents
     *
     * @return list<array{offset: int, score: float}>
     */
    private function rankDocuments(string $query, array $documents): array
    {
        $scores = [];
        foreach (array_chunk($documents, self::BATCH_SIZE, true) as $batch) {
            $payloadDocuments = array_values(array_map(
                static fn (string $text): string => mb_substr($text, 0, self::DOCUMENT_CHARACTERS, 'UTF-8'),
                $batch,
            ));
            $raw = $this->client->request($this->model, [
                'query' => $query,
                'texts' => $payloadDocuments,
            ])->getData();

            $entries = $raw['results'] ?? null;
            if (!\is_array($entries) || \count($entries) !== \count($batch)) {
                throw IndexException::sanitized('Reranker returned an invalid result count.');
            }

            $keys = array_keys($batch);
            $seen = [];
            foreach ($entries as $entry) {
                $index = \is_array($entry) ? ($entry['index'] ?? null) : null;
                $score = \is_array($entry) ? ($entry['relevance_score'] ?? null) : null;
                if (!\is_int($index) || !isset($keys[$index]) || isset($seen[$index]) || (!\is_float($score) && !\is_int($score)) || !is_finite((float) $score)) {
                    throw IndexException::sanitized('Reranker returned invalid document scores.');
                }
                $scores[$keys[$index]] = (float) $score;
                $seen[$index] = true;
            }
        }

        // Filter only after validating every complete response.
        $scores = array_filter($scores, static fn (float $score): bool => $score >= self::MIN_SCORE);
        ksort($scores);
        arsort($scores, \SORT_NUMERIC);

        $ranked = [];
        foreach ($scores as $offset => $score) {
            $ranked[] = ['offset' => $offset, 'score' => $score];
        }

        return $ranked;
    }

    private function baseUrl(string $url): string
    {
        $normalized = rtrim($url, '/');
        if (str_ends_with($normalized, '/v1')) {
            return substr($normalized, 0, -3);
        }

        return $normalized;
    }
}
