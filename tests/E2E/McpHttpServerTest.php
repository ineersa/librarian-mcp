<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ProjectHealth;
use App\Index\Source\MemoryKind;
use App\Mcp\MemoryContextResolver;
use App\Mcp\MemoryTools;
use App\Tests\Support\LibrarianNetworkBrowserTest;
use App\Tests\Support\ProjectSourceBrowserHelpers;
use App\Tests\Support\ProjectSourceFixture;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\HttpClient\Psr18Client;

final class McpHttpServerTest extends LibrarianNetworkBrowserTest
{
    use ProjectSourceBrowserHelpers;

    public function testProjectScopedMemorySearchAndRecall(): void
    {
        $this->login();
        $token = $this->regenerateMcpToken();

        $beforeA = ProjectSourceFixture::hashTree(ProjectSourceFixture::builderProjectPath('mcp-a'));
        $beforeB = ProjectSourceFixture::hashTree(ProjectSourceFixture::builderProjectPath('mcp-b'));
        // Registration uses the same authentication setting as retrieval.
        $first = $this->client(0 === self::getContainer()->getParameter('app.mcp_auth_required') ? null : $token, 'mcp-a');
        $second = null;
        $projectA = null;
        $projectB = null;
        try {
            self::assertSame(ProtocolVersion::V2026_07_28, $first->getProtocolVersion());
            $tools = [];
            foreach ($first->listTools()->tools as $tool) {
                $tools[$tool->name] = $tool->inputSchema;
            }
            ksort($tools);
            self::assertSame([MemoryTools::ENSURE_PROJECT, MemoryTools::SEARCH, MemoryTools::RECALL], array_keys($tools));
            self::assertEquals([
                'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false,
            ], $tools[MemoryTools::ENSURE_PROJECT]);
            self::assertSame(MemoryTools::SEARCH_SCHEMA, $tools[MemoryTools::SEARCH]);
            self::assertSame(MemoryTools::RECALL_SCHEMA, $tools[MemoryTools::RECALL]);

            $created = $this->payload($first->callTool(MemoryTools::ENSURE_PROJECT, []));
            $projectA = (string) $created['project']['id'];
            self::assertTrue($created['created']);
            self::assertSame('mcp-a', $created['project']['name']);
            self::assertSame(ProjectHealth::Pending->value, $created['source']['state']);
            self::assertIsBool($created['index']['searchable']);
            $existing = $this->payload($first->callTool(MemoryTools::ENSURE_PROJECT, []));
            self::assertFalse($existing['created']);
            self::assertSame($created['project'], $existing['project']);

            $this->openProjectDetail($projectA);
            $this->waitForProjectHealth(ProjectHealth::Ready);
            $this->importLegacyOm($projectA, ProjectSourceFixture::defaultOmDatabasePath('mcp-a'));
            $this->waitForCommittedIndex(3);
            $projectB = $this->registerProject('MCP beta', ProjectSourceFixture::visibleProjectPath('mcp-b'));
            $this->openProjectDetail($projectB);
            $this->waitForProjectHealth(ProjectHealth::Ready);
            $this->importLegacyOm($projectB, ProjectSourceFixture::defaultOmDatabasePath('mcp-b'));
            $this->waitForCommittedIndex(3);

            $search = $this->payload($first->callTool(MemoryTools::SEARCH, ['query' => 'synthetic']));
            self::assertSame(MemoryTools::DEFAULT_LIMIT, $search['limit']);
            self::assertSame(3, $search['count']);
            self::assertSame((int) $projectA, $search['project']['id']);
            self::assertFalse($search['partial']);

            $dated = $this->payload($first->callTool(MemoryTools::SEARCH, [
                'query' => 'synthetic', 'after' => '2026-01-02', 'before' => '2026-01-02 10:30',
            ]));
            self::assertSame([ProjectSourceFixture::memoryId(MemoryKind::Observation, 2)], array_column($dated['results'], 'id'));
            self::assertSame('synthetic observation mcp-a-2', $dated['results'][0]['content']);
            self::assertSame('medium', $dated['results'][0]['importance']);

            $recalled = $this->payload($first->callTool(MemoryTools::RECALL, ['id' => substr($dated['results'][0]['id'], 0, 12)]));
            self::assertSame($dated['results'][0]['id'], $recalled['id']);
            self::assertSame('108', $recalled['session_id']);
            self::assertSame([2], array_column($recalled['events'], 'seq'));
            self::assertTrue($recalled['events'][0]['payload']['fixture']);

            $byId = array_column($search['results'], null, 'id');
            $reflectionHit = $byId[ProjectSourceFixture::memoryId(MemoryKind::Reflection, 1)];
            $reflection = $this->payload($first->callTool(MemoryTools::RECALL, [
                'id' => $reflectionHit['id'],
            ]));
            self::assertSame('108', $reflection['session_id']);
            self::assertSame([ProjectSourceFixture::memoryId(MemoryKind::Observation, 1)], $reflection['supporting_observation_ids']);
            self::assertSame([1], array_column($reflection['events'], 'seq'));

            // Same canonical IDs and OM session, but a separately configured project connection.
            $second = $this->client($token, 'mcp-b');
            $otherRegistration = $this->payload($second->callTool(MemoryTools::ENSURE_PROJECT, []));
            self::assertFalse($otherRegistration['created']);
            self::assertSame('MCP beta', $otherRegistration['project']['name']);
            $other = $this->payload($second->callTool(MemoryTools::SEARCH, ['query' => 'synthetic', 'after' => '2026-01-02']));
            self::assertSame((int) $projectB, $other['project']['id']);
            self::assertSame('synthetic observation mcp-b-2', $other['results'][0]['content']);
            $otherRecall = $this->payload($second->callTool(MemoryTools::RECALL, ['id' => $other['results'][0]['id']]));
            self::assertSame('synthetic observation mcp-b-2', $otherRecall['content']);
        } finally {
            $second?->disconnect();
            $first->disconnect();
            if (null !== $projectB) {
                $this->deleteRecord('/admin/projects/'.$projectB);
            }
            if (null !== $projectA) {
                $this->deleteRecord('/admin/projects/'.$projectA);
            }
            ProjectSourceFixture::assertUnchanged($beforeA, ProjectSourceFixture::hashTree(ProjectSourceFixture::builderProjectPath('mcp-a')), 'MCP alpha');
            ProjectSourceFixture::assertUnchanged($beforeB, ProjectSourceFixture::hashTree(ProjectSourceFixture::builderProjectPath('mcp-b')), 'MCP beta');
        }
    }

    private function client(?string $token, string $slug): Client
    {
        $client = Client::builder()
            ->setClientInfo('librarian-browser-journey', '1.0')
            ->setProtocolVersion(ProtocolVersion::V2026_07_28)
            ->setFallbackProtocolVersion(null)
            ->setMaxRetries(0)
            ->build();
        $headers = [
            MemoryContextResolver::PROJECT_HEADER => ProjectSourceFixture::visibleProjectPath($slug),
        ];
        if (null !== $token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        $client->connect(new HttpTransport($this->url.'/_mcp', $headers, httpClient: new Psr18Client($this->stackHttpClient())));

        return $client;
    }

    /** @return array<string, mixed> */
    private function payload(CallToolResult $result): array
    {
        self::assertFalse($result->isError, json_encode($result->structuredContent, \JSON_THROW_ON_ERROR));
        self::assertIsArray($result->structuredContent);

        return $result->structuredContent;
    }
}
