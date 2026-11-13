<?php

declare(strict_types=1);

namespace App\Index\Store;

use App\Index\IndexException;
use App\Index\Model\ValidatingVectorizer;
use Codewithkyrian\ChromaDB\Client;
use Codewithkyrian\ChromaDB\Exceptions\NotFoundException;
use Symfony\AI\Store\Bridge\ChromaDb\Store as ChromaStore;
use Symfony\AI\Store\Bridge\Sqlite\Store as SqliteStore;
use Symfony\AI\Store\CombinedStore;
use Symfony\AI\Store\Indexer\DocumentIndexer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creates generation-scoped Chroma + SQLite FTS stores with cosine collections.
 */
final class IndexStoreFactory
{
    public const int RRF_K = 60;
    public const int INDEX_CHUNK_SIZE = 4;
    private const string COSINE_SPACE = 'cosine';
    private const string TABLE = 'librarian_document';

    private readonly Client $client;
    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly ValidatingVectorizer $vectorizer,
        ChromaClientFactory $chromaClientFactory,
        private readonly string $storageDir,
        ?Client $client = null,
        ?Filesystem $filesystem = null,
    ) {
        $this->client = $client ?? $chromaClientFactory->create();
        $this->filesystem = $filesystem ?? new Filesystem();
        $this->filesystem->mkdir($this->storageDir, 0700);
    }

    /**
     * Official Chroma client shared with diagnostics / capability checks.
     */
    public function client(): Client
    {
        return $this->client;
    }

    public function forGeneration(int $projectId, int $generationId): IndexStores
    {
        $this->assertIds($projectId, $generationId);
        $collectionName = $this->collectionName($projectId, $generationId);
        $this->ensureCosineCollection($collectionName);

        $vector = new ChromaVectorStoreAdapter(new ChromaStore($this->client, $collectionName));
        [$lexicalStore, $pdo] = $this->lexicalStore($projectId, $generationId);
        $text = new SqliteLexicalStoreAdapter($lexicalStore, $pdo);
        $combined = new CombinedStore($vector, $text, self::RRF_K);
        $indexer = new DocumentIndexer(new DocumentProcessor($this->vectorizer, $combined));

        return new IndexStores($combined, $indexer, $vector, $text);
    }

    /**
     * Deletes only the deterministic Librarian-owned collection and lexical file.
     */
    public function removeGeneration(int $projectId, int $generationId): void
    {
        $this->assertIds($projectId, $generationId);
        $collectionName = $this->collectionName($projectId, $generationId);
        try {
            $this->client->deleteCollection($collectionName);
        } catch (NotFoundException) {
            // Already absent.
        } catch (\Throwable $error) {
            throw IndexException::sanitized('Failed to delete Chroma collection.', $error);
        }

        $path = $this->lexicalPath($projectId, $generationId);
        if ($this->filesystem->exists($path)) {
            $this->filesystem->remove($path);
        }
        foreach ([$path.'-wal', $path.'-shm'] as $sidecar) {
            if ($this->filesystem->exists($sidecar)) {
                $this->filesystem->remove($sidecar);
            }
        }
    }

    public function collectionName(int $projectId, int $generationId): string
    {
        return \sprintf('librarian_p%d_g%d', $projectId, $generationId);
    }

    private function ensureCosineCollection(string $collectionName): void
    {
        try {
            try {
                $existing = $this->client->getCollection($collectionName);
            } catch (NotFoundException) {
                $existing = $this->client->createCollection($collectionName, [
                    'hnsw:space' => self::COSINE_SPACE,
                ]);
            }

            $space = $existing->metadata['hnsw:space'] ?? null;
            if (self::COSINE_SPACE !== $space) {
                throw IndexException::sanitized(\sprintf('Chroma collection "%s" is not configured for cosine distance.', $collectionName));
            }
        } catch (IndexException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw IndexException::sanitized('Failed to prepare Chroma collection.', $error);
        }
    }

    /**
     * @return array{0: SqliteStore, 1: \PDO}
     */
    private function lexicalStore(int $projectId, int $generationId): array
    {
        $directory = \dirname($this->lexicalPath($projectId, $generationId));
        $this->filesystem->mkdir($directory, 0700);
        $path = $this->lexicalPath($projectId, $generationId);

        try {
            $pdo = new \PDO('sqlite:'.$path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            @chmod($path, 0600);
            $store = SqliteStore::fromPdo($pdo, self::TABLE);
            $store->setup();
        } catch (\Throwable $error) {
            throw IndexException::sanitized('Failed to prepare lexical store.', $error);
        }

        return [$store, $pdo];
    }

    private function lexicalPath(int $projectId, int $generationId): string
    {
        return rtrim($this->storageDir, '/').'/p'.$projectId.'/g'.$generationId.'.sqlite';
    }

    private function assertIds(int $projectId, int $generationId): void
    {
        if ($projectId < 1 || $generationId < 1) {
            throw new \InvalidArgumentException('Project and generation IDs must be positive integers.');
        }
    }
}
