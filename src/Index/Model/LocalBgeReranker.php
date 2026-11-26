<?php

declare(strict_types=1);

namespace App\Index\Model;

use App\Index\IndexException;
use Symfony\AI\Platform\Bridge\Voyage\Reranker;
use Symfony\AI\Platform\Bridge\Voyage\Reranker\ModelClient as VoyageRerankerClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * BGE reranker via Voyage-compatible request client + llama.cpp result validation.
 *
 * The endpoint is resolved from Settings on first use and again after a kernel reset.
 */
final class LocalBgeReranker implements ResetInterface
{
    public const int BATCH_SIZE = 8;
    public const int DOCUMENT_CHARACTERS = 768;
    public const float MIN_SCORE = -4.0;

    private readonly HttpClientInterface $http;
    private ?VoyageRerankerClient $client = null;
    private ?Reranker $model = null;

    public function __construct(
        private readonly ModelEndpoints $endpoints,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->http = $httpClient ?? HttpClient::create([
            'timeout' => 30.0,
            'max_duration' => 30.0,
            'extra' => [
                'trace_content' => false,
            ],
        ]);
    }

    public function reset(): void
    {
        $this->client = null;
        $this->model = null;
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
        if (null === $this->client || null === $this->model) {
            $endpoint = $this->endpoints->reranker();
            $this->client = new VoyageRerankerClient($this->http, $endpoint->apiKey ?? '', $endpoint->baseUrl());
            $this->model = new Reranker($endpoint->model);
        }

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
}
