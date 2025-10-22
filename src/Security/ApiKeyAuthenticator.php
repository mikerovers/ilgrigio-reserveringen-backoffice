<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private string $apiKey
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('X-API-KEY');
    }

    public function authenticate(Request $request): Passport
    {
        $apiKey = $request->headers->get('X-API-KEY');

        if (null === $apiKey) {
            throw new CustomUserMessageAuthenticationException('No API key provided');
        }

        if ($apiKey !== $this->apiKey) {
            throw new CustomUserMessageAuthenticationException('Invalid API key');
        }

        return new SelfValidatingPassport(new UserBadge($apiKey));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // On success, let the request continue
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => 'Authentication failed',
            'message' => strtr($exception->getMessageKey(), $exception->getMessageData())
        ], Response::HTTP_UNAUTHORIZED);
    }
}
