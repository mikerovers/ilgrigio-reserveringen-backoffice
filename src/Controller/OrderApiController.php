<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\OrderListItemResponse;
use App\DTO\OrderNoteRequest;
use App\DTO\OrderNoteResponse;
use App\DTO\OrderProcessResponse;
use App\DTO\PageQuery;
use App\DTO\PaginatedResponse;
use App\Entity\OrderNote;
use App\Event\OrderCompletedEvent;
use App\Repository\OrderNoteRepository;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
final class OrderApiController extends AbstractController
{
    public function __construct(
        private OrderRepository $orderRepository,
        private OrderNoteRepository $orderNoteRepository,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Admin re-trigger of order fulfilment (re-issues tickets idempotently and
     * re-sends the confirmation email) for an existing order.
     */
    #[Route('/orders/{orderNumber}/process', name: 'order_process', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[Security(name: 'BearerAuth')]
    #[OA\Response(
        response: 200,
        description: 'Order fulfilment result',
        content: new Model(type: OrderProcessResponse::class),
    )]
    public function processOrder(string $orderNumber): JsonResponse
    {
        $this->logger->info('API order processing request received', [
            'order_number' => $orderNumber,
        ]);

        $order = $this->orderRepository->findOneByOrderNumber($orderNumber);

        if ($order === null) {
            return $this->json(
                new OrderProcessResponse(
                    success: false,
                    orderNumber: $orderNumber,
                    message: "Order {$orderNumber} could not be found",
                ),
                Response::HTTP_NOT_FOUND,
            );
        }

        if (!$order->status->triggersFulfilment()) {
            return $this->json(
                new OrderProcessResponse(
                    success: false,
                    orderNumber: $orderNumber,
                    message: "Order {$orderNumber} is not completed (status: {$order->status->value})",
                ),
                Response::HTTP_CONFLICT,
            );
        }

        $this->eventDispatcher->dispatch(new OrderCompletedEvent($order));

        return $this->json(new OrderProcessResponse(
            success: true,
            orderNumber: $orderNumber,
            message: 'Order fulfilment re-dispatched',
        ));
    }

    #[Route('/orders', name: 'order_list', methods: ['GET'])]
    #[IsGranted('ROLE_REGISTER')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Orders')]
    #[OA\Parameter(name: 'page', description: 'Page number', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1))]
    #[OA\Parameter(name: 'limit', description: 'Items per page (max 100)', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20))]
    #[OA\Parameter(name: 'orderDirection', description: 'Order by createdAt', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ASC', 'DESC'], default: 'DESC'))]
    #[OA\Response(
        response: 200,
        description: 'Paginated list of orders',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: new Model(type: PaginatedResponse::class)),
                new OA\Schema(properties: [
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(ref: new Model(type: OrderListItemResponse::class)),
                    ),
                ]),
            ],
        ),
    )]
    public function listOrders(#[MapQueryString] PageQuery $query = new PageQuery()): JsonResponse
    {
        $paginator = $this->orderRepository->findPaginated($query);

        return $this->json(new PaginatedResponse(
            items: array_map(
                OrderListItemResponse::fromEntity(...),
                iterator_to_array($paginator->getIterator(), false),
            ),
            page: $query->page,
            limit: $query->limit,
            total: count($paginator),
        ));
    }

    #[Route('/orders/{orderNumber}/notes', name: 'order_notes_list', methods: ['GET'])]
    #[IsGranted('ROLE_REGISTER')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Orders')]
    #[OA\Parameter(name: 'page', description: 'Page number', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1))]
    #[OA\Parameter(name: 'limit', description: 'Items per page (max 100)', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20))]
    #[OA\Parameter(name: 'orderDirection', description: 'Order by createdAt', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ASC', 'DESC'], default: 'DESC'))]
    #[OA\Response(
        response: 200,
        description: 'Paginated list of notes of an order',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: new Model(type: PaginatedResponse::class)),
                new OA\Schema(properties: [
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(ref: new Model(type: OrderNoteResponse::class)),
                    ),
                ]),
            ],
        ),
    )]
    #[OA\Response(response: 404, description: 'Order not found')]
    public function listNotes(
        string $orderNumber,
        #[MapQueryString] PageQuery $query = new PageQuery(),
    ): JsonResponse {
        $order = $this->orderRepository->findOneByOrderNumber($orderNumber);

        if ($order === null) {
            throw $this->createNotFoundException("Order {$orderNumber} could not be found");
        }

        $paginator = $this->orderNoteRepository->findByOrderPaginated($order, $query);

        return $this->json(new PaginatedResponse(
            items: array_map(
                OrderNoteResponse::fromEntity(...),
                iterator_to_array($paginator->getIterator(), false),
            ),
            page: $query->page,
            limit: $query->limit,
            total: count($paginator),
        ));
    }

    #[Route('/orders/{orderNumber}/notes', name: 'order_notes_create', methods: ['POST'])]
    #[IsGranted('ROLE_REGISTER')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Orders')]
    #[OA\RequestBody(content: new Model(type: OrderNoteRequest::class))]
    #[OA\Response(response: 201, description: 'Created note', content: new Model(type: OrderNoteResponse::class))]
    #[OA\Response(response: 404, description: 'Order not found')]
    public function createNote(
        string $orderNumber,
        #[MapRequestPayload] OrderNoteRequest $request,
    ): JsonResponse {
        $order = $this->orderRepository->findOneByOrderNumber($orderNumber);

        if ($order === null) {
            throw $this->createNotFoundException("Order {$orderNumber} could not be found");
        }

        $note = new OrderNote($order, trim($request->content));
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $this->json(OrderNoteResponse::fromEntity($note), Response::HTTP_CREATED);
    }
}
