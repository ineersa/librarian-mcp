<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\LibrarianNetworkBrowserTest;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Component\HttpFoundation\Response;

final class McpHttpServerTest extends LibrarianNetworkBrowserTest
{
    public function testAuthenticatedInitializeAndToolsList(): void
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

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$token,
        ];
        $initialize = $this->page->request()->post($this->url.'/_mcp', [
            'headers' => $headers,
            'data' => [
                'jsonrpc' => MessageInterface::JSONRPC_VERSION,
                'method' => InitializeRequest::getMethod(),
                'params' => [
                    'protocolVersion' => ProtocolVersion::V2025_03_26->value,
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'phpunit-browser', 'version' => '1.0'],
                ],
                'id' => 1,
            ],
        ]);
        self::assertSame(Response::HTTP_OK, $initialize->status());
        $sessionId = $initialize->headers()[strtolower(StreamableHttpTransport::SESSION_HEADER)] ?? null;
        self::assertNotNull($sessionId);
        $headers[StreamableHttpTransport::SESSION_HEADER] = $sessionId;
        $headers[StreamableHttpTransport::PROTOCOL_VERSION_HEADER] = ProtocolVersion::V2025_03_26->value;

        $initialized = $this->page->request()->post($this->url.'/_mcp', [
            'headers' => $headers,
            'data' => [
                'jsonrpc' => MessageInterface::JSONRPC_VERSION,
                'method' => InitializedNotification::getMethod(),
            ],
        ]);
        self::assertSame(Response::HTTP_ACCEPTED, $initialized->status());

        $toolsList = $this->page->request()->post($this->url.'/_mcp', [
            'headers' => $headers,
            'data' => [
                'jsonrpc' => MessageInterface::JSONRPC_VERSION,
                'method' => ListToolsRequest::getMethod(),
                'id' => 2,
            ],
        ]);
        self::assertSame(Response::HTTP_OK, $toolsList->status());
        $body = $toolsList->text();
        self::assertStringContainsString('search-libraries', $body);
        self::assertStringContainsString('semantic-search', $body);
        self::assertStringContainsString('read', $body);
        self::assertStringContainsString('grep', $body);
    }
}
