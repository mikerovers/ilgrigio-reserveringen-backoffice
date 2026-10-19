<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\MeResponse;
use App\Entity\User;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
final class MeApiController extends AbstractController
{
    #[Route('/me', name: 'me', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Me')]
    #[OA\Response(response: 200, description: 'The logged-in user', content: new Model(type: MeResponse::class))]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(MeResponse::fromEntity($user));
    }
}
