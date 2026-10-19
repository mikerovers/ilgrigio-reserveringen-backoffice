<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CouponListItemResponse;
use App\DTO\CouponListQuery;
use App\DTO\PaginatedResponse;
use App\Entity\Coupon;
use App\Repository\CouponRepository;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/coupons', name: 'api_coupons_')]
#[IsGranted('ROLE_REGISTER')]
final class CouponApiController extends AbstractController
{
    public function __construct(
        private CouponRepository $couponRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Coupons')]
    #[OA\Parameter(name: 'search', description: 'Case-insensitive match on (part of) the coupon code', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'page', description: 'Page number', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1))]
    #[OA\Parameter(name: 'limit', description: 'Items per page (max 100)', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20))]
    #[OA\Parameter(name: 'orderDirection', description: 'Order by createdAt', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ASC', 'DESC'], default: 'DESC'))]
    #[OA\Response(
        response: 200,
        description: 'Paginated list of coupons',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: new Model(type: PaginatedResponse::class)),
                new OA\Schema(properties: [
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(ref: new Model(type: CouponListItemResponse::class)),
                    ),
                ]),
            ],
        ),
    )]
    public function list(#[MapQueryString] CouponListQuery $query = new CouponListQuery()): JsonResponse
    {
        $paginator = $this->couponRepository->findPaginated($query);

        return $this->json(new PaginatedResponse(
            items: array_map(
                CouponListItemResponse::fromEntity(...),
                iterator_to_array($paginator->getIterator(), false),
            ),
            page: $query->page,
            limit: $query->limit,
            total: count($paginator),
        ));
    }

    /**
     * Deactivates the coupon (active = false). Idempotent; takes no body.
     */
    #[Route('/{id}', name: 'deactivate', methods: ['PUT'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Coupons')]
    #[OA\Response(response: 200, description: 'Deactivated coupon', content: new Model(type: CouponListItemResponse::class))]
    #[OA\Response(response: 404, description: 'Coupon not found')]
    public function deactivate(#[MapEntity] Coupon $coupon): JsonResponse
    {
        if ($coupon->active) {
            $coupon->active = false;
            $this->entityManager->flush();
        }

        return $this->json(CouponListItemResponse::fromEntity($coupon));
    }
}
