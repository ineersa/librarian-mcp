<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\LibrarianNetworkBrowserTest;

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
        $page->getByRole('link', ['name' => 'Regenerate MCP token', 'exact' => true])->click();
        $page->locator('#flash-messages')->waitFor();
        $flashText = $page->locator('#flash-messages')->textContent();
        self::assertMatchesRegularExpression('/mcp_[0-9a-f]{48}/', $flashText);
        preg_match('/mcp_[0-9a-f]{48}/', $flashText, $matches);
        $token = $matches[0];

        $unauthorized = $this->page->request()->post($this->url.'/_mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'data' => [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => 1,
            ],
        ]);
        self::assertSame(401, $unauthorized->status());

        $initialize = $this->page->request()->post($this->url.'/_mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
            'data' => [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-03-26',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'phpunit-browser', 'version' => '1.0'],
                ],
                'id' => 1,
            ],
        ]);
        self::assertSame(200, $initialize->status());
        $sessionId = $initialize->headers()['mcp-session-id']
            ?? $initialize->headers()['Mcp-Session-Id']
            ?? null;
        if (null === $sessionId) {
            foreach ($initialize->headersArray() as $header) {
                if (\is_array($header) && isset($header['name'], $header['value']) && 0 === strcasecmp((string) $header['name'], 'Mcp-Session-Id')) {
                    $sessionId = (string) $header['value'];
                    break;
                }
            }
        }
        self::assertNotNull($sessionId);

        $toolsList = $this->page->request()->post($this->url.'/_mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$token,
                'Mcp-Session-Id' => $sessionId,
            ],
            'data' => [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => 2,
            ],
        ]);
        self::assertSame(200, $toolsList->status());
        $body = $toolsList->text();
        self::assertStringContainsString('search-libraries', $body);
        self::assertStringContainsString('semantic-search', $body);
        self::assertStringContainsString('read', $body);
        self::assertStringContainsString('grep', $body);
    }
}
