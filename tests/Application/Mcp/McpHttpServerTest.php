<?php

declare(strict_types=1);

namespace App\Tests\Application\Mcp;

use App\Entity\User;
use App\Security\McpTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class McpHttpServerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        foreach ($em->getRepository(User::class)->findAll() as $user) {
            $em->remove($user);
        }
        $em->flush();

        self::ensureKernelShutdown();
    }

    public function testMcpEndpointRequiresToken(): void
    {
        $client = static::createClient();

        $client->request('POST', '/_mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 1,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(401);
    }

    public function testMcpEndpointAcceptsBearerTokenAndListsTools(): void
    {
        $token = 'mcp_test_token';

        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp-user@example.com';
        $user->roles = ['ROLE_MCP'];
        $user->password = 'not-used-in-mcp';
        $user->setMcpTokenHash(McpTokenManager::hashToken($token));
        $em->persist($user);
        $em->flush();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('POST', '/_mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
            'id' => 1,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $sessionId = $client->getResponse()->headers->get('Mcp-Session-Id');
        $this->assertNotNull($sessionId);

        $client->request('POST', '/_mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_MCP_SESSION_ID' => $sessionId,
        ], content: json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 2,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('search-libraries', $client->getResponse()->getContent());
        $this->assertStringContainsString('semantic-search', $client->getResponse()->getContent());
        $this->assertStringContainsString('read', $client->getResponse()->getContent());
        $this->assertStringContainsString('grep', $client->getResponse()->getContent());
    }
}
