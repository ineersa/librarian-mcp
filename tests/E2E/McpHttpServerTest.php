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
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

final class McpHttpServerTest extends LibrarianNetworkBrowserTest
{
    use ProjectSourceBrowserHelpers;

    public function testProjectScopedMemorySearchAndRecall(): void
    {
        $this->login();
        $page = $this->visit('/admin/users?query='.rawurlencode('admin@test.com'));
        $adminRow = $page->locator('tbody tr')->filter(['hasText' => 'admin@test.com']);
        $adminEditUrl = $adminRow->locator('a.action-edit')->getAttribute('href');
        self::assertNotNull($adminEditUrl);
        $page->goto($adminEditUrl);
        $page->getByRole('button', ['name' => 'Regenerate MCP token'])->click();
        $page->locator('#flash-messages')->waitFor();
        $flashText = $page->locator('#flash-messages')->textContent();
        self::assertMatchesRegularExpression('/mcp_[0-9a-f]{48}/', $flashText);
        preg_match('/mcp_[0-9a-f]{48}/', $flashText, $matches);
        $token = $matches[0];

        $beforeA = ProjectSourceFixture::hashTree(ProjectSourceFixture::builderProjectPath('mcp-a'));
        $beforeB = ProjectSourceFixture::hashTree(ProjectSourceFixture::builderProjectPath('mcp-b'));
        $projectA = $this->registerProject('MCP alpha', ProjectSourceFixture::visibleProjectPath('mcp-a'));
        $this->openProjectDetail($projectA);
        $this->waitForProjectHealth(ProjectHealth::Ready);
        $this->waitForCommittedIndex(3);
        $projectB = $this->registerProject('MCP beta', ProjectSourceFixture::visibleProjectPath('mcp-b'));
        $this->openProjectDetail($projectB);
        $this->waitForProjectHealth(ProjectHealth::Ready);
        $this->waitForCommittedIndex(3);

        $first = $this->client($token, 'mcp-a');
        $second = null;
        try {
            self::assertSame(ProtocolVersion::V2026_07_28, $first->getProtocolVersion());
            $tools = [];
            foreach ($first->listTools()->tools as $tool) {
                $tools[$tool->name] = $tool->inputSchema;
            }
            ksort($tools);
            self::assertSame([MemoryTools::SEARCH, MemoryTools::RECALL], array_keys($tools));
            self::assertSame(MemoryTools::SEARCH_SCHEMA, $tools[MemoryTools::SEARCH]);
            self::assertSame(MemoryTools::RECALL_SCHEMA, $tools[MemoryTools::RECALL]);

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
                'id' => $reflectionHit['id'], 'session_id' => $reflectionHit['session_id'],
            ]));
            self::assertSame('108', $reflection['session_id']);
            self::assertSame([ProjectSourceFixture::memoryId(MemoryKind::Observation, 1)], $reflection['supporting_observation_ids']);
            self::assertSame([1], array_column($reflection['events'], 'seq'));

            // Same canonical IDs and OM session, but a separately configured project connection.
            $second = $this->client($token, 'mcp-b');
            $other = $this->payload($second->callTool(MemoryTools::SEARCH, ['query' => 'synthetic', 'after' => '2026-01-02']));
            self::assertSame((int) $projectB, $other['project']['id']);
            self::assertSame('synthetic observation mcp-b-2', $other['results'][0]['content']);
            $otherRecall = $this->payload($second->callTool(MemoryTools::RECALL, ['id' => $other['results'][0]['id']]));
            self::assertSame('synthetic observation mcp-b-2', $otherRecall['content']);
        } finally {
            $second?->disconnect();
            $first->disconnect();
            $this->deleteRecord('/admin/projects/'.$projectB);
            $this->deleteRecord('/admin/projects/'.$projectA);
            ProjectSourceFixture::assertUnchanged($beforeA, ProjectSourceFixture::hashTree(ProjectSourceFixture::builderProjectPath('mcp-a')), 'MCP alpha');
            ProjectSourceFixture::assertUnchanged($beforeB, ProjectSourceFixture::hashTree(ProjectSourceFixture::builderProjectPath('mcp-b')), 'MCP beta');
        }
    }

    private function client(string $token, string $slug): Client
    {
        $hostname = parse_url($this->url, \PHP_URL_HOST);
        $proxyIp = getenv('LIBRARIAN_PROXY_IP');
        if (!\is_string($hostname) || false === $proxyIp || '' === $proxyIp) {
            throw new \LogicException('The SDK client requires the isolated browser proxy hostname and IP.');
        }
        $client = Client::builder()
            ->setClientInfo('librarian-browser-journey', '1.0')
            ->setProtocolVersion(ProtocolVersion::V2026_07_28)
            ->setFallbackProtocolVersion(null)
            ->setMaxRetries(0)
            ->build();
        $client->connect(new HttpTransport($this->url.'/_mcp', [
            'Authorization' => 'Bearer '.$token,
            MemoryContextResolver::PROJECT_HEADER => ProjectSourceFixture::visibleProjectPath($slug),
            MemoryContextResolver::SESSION_HEADER => '108',
        ], httpClient: new Psr18Client(HttpClient::create(['resolve' => [$hostname => $proxyIp]]))));

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
