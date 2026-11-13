<?php

declare(strict_types=1);

namespace App\Command;

use App\Index\IndexException;
use App\Index\IndexFields;
use App\Index\Model\LocalBgeReranker;
use App\Index\Model\ValidatingVectorizer;
use App\Index\Store\ChromaVectorStoreAdapter;
use App\Index\Store\IndexStoreFactory;
use Composer\InstalledVersions;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\ChromaDb\Store;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:indexing:capabilities', description: 'Verify the selected Chroma/client bridge and optionally the configured local models using public synthetic documents.')]
final class IndexingCapabilitiesCommand extends Command
{
    private const string COSINE = 'cosine';
    private const string AXIS_ID = 'capability-axis';
    private const string ORTHOGONAL_ID = 'capability-orthogonal';
    private const string OTHER_PROJECT_ID = 'capability-other-project';
    private const int FIRST_DATE = 1704067200;
    private const int SECOND_DATE = 1735689600;
    private const float DISTANCE_TOLERANCE = 0.000001;

    public function __construct(
        private readonly IndexStoreFactory $stores,
        private readonly ValidatingVectorizer $vectorizer,
        private readonly LocalBgeReranker $reranker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('models', null, InputOption::VALUE_NONE, 'Also verify real CodeRank embeddings and the local BGE protocol.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $started = hrtime(true);
        $client = $this->stores->client();
        $name = 'librarian_capability_'.bin2hex(random_bytes(8));
        $created = false;
        $summary = null;
        $failure = null;

        try {
            $collection = $client->createCollection($name, ['hnsw:space' => self::COSINE]);
            $created = true;
            $this->requireCondition(self::COSINE === ($collection->metadata['hnsw:space'] ?? null), 'Collection cosine metadata was not retained.');
            $store = new Store($client, $name);
            $store->add([
                $this->document(self::AXIS_ID, [2.0, 0.0], 1, self::FIRST_DATE, 'Public synthetic memory about project storage.'),
                $this->document(self::ORTHOGONAL_ID, [0.0, 1.0], 1, self::SECOND_DATE, 'Public synthetic memory about a later deployment.'),
                $this->document(self::OTHER_PROJECT_ID, [1.0, 0.0], 2, self::FIRST_DATE, 'Public synthetic memory belonging to another project.'),
            ]);
            $this->requireCondition(3 === $store->count(), 'Chroma create/upsert count did not match.');
            $store->add($this->document(self::AXIS_ID, [2.0, 0.0], 1, self::FIRST_DATE, 'Updated public synthetic project storage memory.'));
            $this->requireCondition(3 === $store->count(), 'Idempotent upsert duplicated an ID.');

            $options = [
                IndexFields::PROJECT_ID => 1,
                'until' => self::SECOND_DATE,
                'limit' => 1,
                'include' => ['documents', 'embeddings', 'metadatas', 'distances'],
            ];
            $adapter = new ChromaVectorStoreAdapter($store);
            $hits = iterator_to_array($adapter->query(new VectorQuery(new Vector([1.0, 0.0])), $options), false);
            $this->requireCondition(1 === \count($hits) && self::AXIS_ID === (string) $hits[0]->getId(), 'Native project/date filter or result limit did not match.');
            $score = $hits[0]->getScore();
            $this->requireCondition(null !== $score && abs($score) < self::DISTANCE_TOLERANCE, 'Observed distance does not match explicit cosine configuration.');
            $this->requireCondition('Updated public synthetic project storage memory.' === $hits[0]->getMetadata()->getText(), 'Updated document text was not returned.');
            $this->requireCondition(2 === $hits[0]->getVector()->getDimensions(), 'Explicit vectors were not returned.');
            $this->requireCondition(self::COSINE === ($client->getCollection($name)->metadata['hnsw:space'] ?? null), 'Collection configuration did not survive another client read.');

            $store->remove([self::AXIS_ID, self::ORTHOGONAL_ID, self::OTHER_PROJECT_ID]);
            $this->requireCondition(0 === $store->count(), 'Chroma delete did not remove the supplied IDs.');

            $modelEvidence = null;
            if (true === $input->getOption('models')) {
                $vector = $this->vectorizer->embedQuery('Where is project memory stored?');
                $ranking = $this->reranker->rank('Where is project memory stored?', [
                    'Project memory is stored in the local observational memory SQLite database.',
                    'A public synthetic note about an unrelated afternoon walk.',
                ]);
                $this->requireCondition([] !== $ranking && 0 === $ranking[0], 'Local BGE did not rank the matching public document first.');
                $modelEvidence = ['embedding_dimensions' => $vector->getDimensions(), 'reranker_complete_response_validated' => true];
            }

            $summary = [
                'status' => 'passed',
                'server' => $client->version(),
                'client' => InstalledVersions::getPrettyVersion('codewithkyrian/chromadb-php'),
                'bridge' => InstalledVersions::getPrettyVersion('symfony/ai-chroma-db-store'),
                'cosine_observed' => true,
                'explicit_vectors' => true,
                'upsert_update_delete' => true,
                'native_project_date_filter' => true,
                'limit' => 1,
                'models' => $modelEvidence,
                'elapsed_ms' => (hrtime(true) - $started) / 1_000_000,
                'php_live_bytes' => memory_get_usage(),
                'php_allocated_bytes' => memory_get_usage(true),
                'php_peak_bytes' => memory_get_peak_usage(),
            ];
        } catch (IndexException $exception) {
            $failure = $exception->getMessage();
        } catch (\Throwable) {
            // Never print a provider response, memory text, vector, or exception chain.
            $failure = 'Indexing capability verification failed. No private provider response was logged.';
        } finally {
            if ($created) {
                try {
                    $client->deleteCollection($name);
                } catch (\Throwable) {
                    $failure = 'Could not remove the owned synthetic capability collection.';
                }
            }
        }

        if (null !== $failure) {
            $output->writeln($failure);

            return self::FAILURE;
        }

        $output->writeln(json_encode($summary, \JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** @param list<float> $vector */
    private function document(string $id, array $vector, int $projectId, int $date, string $text): VectorDocument
    {
        $metadata = new Metadata([
            IndexFields::PROJECT_ID => $projectId,
            IndexFields::OCCURRED_AT => $date,
        ]);
        $metadata->setText($text);

        return new VectorDocument($id, new Vector($vector), $metadata);
    }

    private function requireCondition(bool $condition, string $message): void
    {
        if (!$condition) {
            throw IndexException::sanitized($message);
        }
    }
}
