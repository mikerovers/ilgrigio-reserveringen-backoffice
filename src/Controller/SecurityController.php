<?php

declare(strict_types=1);

namespace App\Controller;

use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SecurityController extends AbstractController
{
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    #[Security(name: null)]
    #[OA\RequestBody(
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string'),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Authentication succeeded, JWT + refresh token issued',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'refresh_token', type: 'string'),
            ],
        ),
    )]
    #[OA\Response(
        response: 401,
        description: 'Invalid credentials',
    )]
    public function login(): Response
    {
        // The json_login authenticator on the "login" firewall intercepts
        // POST /api/login before this action ever executes. This method
        // exists only so the route is resolvable by the router.
        throw new \LogicException('This should never be reached — json_login intercepts this route first.');
    }

    #[Route('/api/token/refresh', name: 'api_token_refresh', methods: ['POST'])]
    #[Security(name: null)]
    #[OA\RequestBody(
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'refresh_token', type: 'string'),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Refresh succeeded, new JWT + refresh token issued',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'refresh_token', type: 'string'),
            ],
        ),
    )]
    #[OA\Response(
        response: 401,
        description: 'Refresh token missing, invalid, or expired',
    )]
    public function refresh(): Response
    {
        // The refresh-jwt authenticator on the "refresh" firewall intercepts
        // POST /api/token/refresh before this action ever executes. This
        // method exists only so the route is resolvable by the router.
        throw new \LogicException('This should never be reached — refresh-jwt intercepts this route first.');
    }
}
