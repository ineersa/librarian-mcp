<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class McpTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly McpTokenManager $tokenManager,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/_mcp');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $token = $this->extractToken($request);

        if (null === $token) {
            throw new CustomUserMessageAuthenticationException('Missing MCP token. Use Authorization: Bearer <token> or X-MCP-Token header.');
        }

        return new SelfValidatingPassport(new UserBadge($token, function (string $plainToken) {
            $user = $this->tokenManager->findUserByToken($plainToken);

            if (null === $user) {
                throw new CustomUserMessageAuthenticationException('Invalid MCP token.');
            }

            if (!$user->hasRole('ROLE_MCP')) {
                throw new CustomUserMessageAuthenticationException('MCP access denied: ROLE_MCP is required.');
            }

            return $user;
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        if ($user instanceof \App\Entity\User) {
            $this->tokenManager->touchLastUsed($user);
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse([
            'message' => $exception->getMessage(),
        ], Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse([
            'message' => 'MCP authentication required.',
        ], Response::HTTP_UNAUTHORIZED);
    }

    private function extractToken(Request $request): ?string
    {
        $auth = $request->headers->get('Authorization');
        if (\is_string($auth) && preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
            return trim($matches[1]);
        }

        $xToken = $request->headers->get('X-MCP-Token');
        if (\is_string($xToken) && '' !== trim($xToken)) {
            return trim($xToken);
        }

        return null;
    }
}
