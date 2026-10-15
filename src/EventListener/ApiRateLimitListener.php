<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * General-purpose throttle for every /api/* request, keyed by the
 * authenticated user when available, otherwise by IP — covers both
 * JWT/API-key protected routes and public ones (e.g. available-events).
 *
 * Runs at priority -10, below the security firewall's kernel.request
 * listener (priority 8), so Security::getUser() reflects the authenticated
 * user by the time this fires instead of racing the firewall.
 *
 * Endpoint-specific limiters (validate_coupon, token_refresh) still apply on
 * top of this for routes that need tighter limits.
 */
#[AsEventListener(event: 'kernel.request', priority: -10)]
final class ApiRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.api')]
        private RateLimiterFactory $apiLimiter,
        private Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $key = $this->security->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?? 'unknown';

        $limit = $this->apiLimiter->create($key)->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['code' => Response::HTTP_TOO_MANY_REQUESTS, 'message' => 'Too many requests, please try again later.'],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $limit->getRetryAfter()->getTimestamp()],
        ));
    }
}
