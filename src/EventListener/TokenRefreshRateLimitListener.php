<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The refresh-jwt authenticator (gesdinet/jwt-refresh-token-bundle) has no
 * controller or security event to hook a rate limiter into, so this throttles
 * /api/token/refresh directly on kernel.request, before the firewall runs.
 */
#[AsEventListener(event: 'kernel.request', priority: 10)]
final class TokenRefreshRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.token_refresh')]
        private RateLimiterFactory $tokenRefreshLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $request->getPathInfo() !== '/api/token/refresh') {
            return;
        }

        $limit = $this->tokenRefreshLimiter->create($request->getClientIp())->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['code' => Response::HTTP_TOO_MANY_REQUESTS, 'message' => 'Too many refresh attempts, try again later.'],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $limit->getRetryAfter()->getTimestamp()],
        ));
    }
}
