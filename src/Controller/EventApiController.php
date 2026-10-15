<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\EventCategoryResponse;
use App\DTO\EventListQuery;
use App\DTO\EventListResponse;
use App\DTO\PaginatedResponse;
use App\Repository\EventCategoryRepository;
use App\Repository\EventRepository;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_events_')]
final class EventApiController extends AbstractController
{
    public function __construct(
        private EventRepository $eventRepository,
        private EventCategoryRepository $eventCategoryRepository,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/events', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_REGISTER')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Events')]
    #[OA\Parameter(name: 'categoryId', description: 'Filter by event category id (UUID)', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'includePast', description: 'Include events that already started/ended', in: 'query', schema: new OA\Schema(type: 'boolean', default: false))]
    #[OA\Parameter(name: 'page', description: 'Page number', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1))]
    #[OA\Parameter(name: 'limit', description: 'Items per page (max 100)', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20))]
    #[OA\Parameter(name: 'orderDirection', description: 'Order direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ASC', 'DESC'], default: 'ASC'))]
    #[OA\Response(
        response: 200,
        description: 'Paginated list of events',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: new Model(type: PaginatedResponse::class)),
                new OA\Schema(properties: [
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(ref: new Model(type: EventListResponse::class)),
                    ),
                ]),
            ],
        ),
    )]
    public function list(#[MapQueryString] EventListQuery $query = new EventListQuery()): JsonResponse
    {
        $paginator = $this->eventRepository->findPaginated($query, $this->clock->now());

        $items = array_map(
            EventListResponse::fromEntity(...),
            iterator_to_array($paginator->getIterator()),
        );

        return $this->json(new PaginatedResponse(
            items: $items,
            page: $query->page,
            limit: $query->limit,
            total: count($paginator),
        ));
    }

    #[Route('/event-categories', name: 'categories', methods: ['GET'])]
    #[IsGranted('ROLE_REGISTER')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Events')]
    #[OA\Response(
        response: 200,
        description: 'List of event categories',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: EventCategoryResponse::class)),
        ),
    )]
    public function categories(): JsonResponse
    {
        $categories = array_map(
            EventCategoryResponse::fromEntity(...),
            $this->eventCategoryRepository->findAllOrdered(),
        );

        return $this->json($categories);
    }
}
