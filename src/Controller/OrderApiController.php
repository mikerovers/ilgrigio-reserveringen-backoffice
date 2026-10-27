<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CreateOrderRequest;
use App\DTO\OrderDetailResponse;
use App\DTO\OrderListItemResponse;
use App\DTO\OrderListQuery;
use App\DTO\OrderNoteRequest;
use App\DTO\OrderNoteResponse;
use App\DTO\OrderProcessResponse;
use App\DTO\OrderTicketsResponse;
use App\DTO\PageQuery;
use App\DTO\PaginatedResponse;
use App\Entity\OrderNote;
use App\Event\OrderCompletedEvent;
use App\Exception\RegisterOrderException;
use App\Repository\OrderNoteRepository;
use App\Repository\OrderRepository;
use App\Repository\TicketRepository;
use App\Entity\User;
use App\Service\OrderCancellationService;
use App\Service\RegisterOrderService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route('/api', name: 'api_')]
final class OrderApiController extends AbstractController
{
    public function __construct(
        private OrderRepository $orderRepository,
        private OrderNoteRepository $orderNoteRepository,
        private TicketRepository $ticketRepository,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private RegisterOrderService $registerOrderService,
        private OrderCancellationService $orderCancellationService,
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
    public function processOrder(string $orderNumber, #[CurrentUser] User $user): JsonResponse
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

        $note = new OrderNote($order, sprintf(
            'Orderverwerking opnieuw gestart door %s (tickets en bevestigingsmail).',
            $user->name !== '' ? $user->name : $user->email,
        ));
        $order->notes->add($note);
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $this->json(new OrderProcessResponse(
            success: true,
            orderNumber: $orderNumber,
            message: 'Order fulfilment re-dispatched',
        ));
    }

    /**
     * Create an order at the register. It is settled immediately as a cash
     * payment (or completes for free when the total is zero) and fulfilled right away.
     * Send an `Idempotency-Key` header so a retried request returns the original order.
     */
    #[Route('/orders', name: 'order_create', methods: ['POST'])]
    #[IsGranted('ROLE_REGISTER')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Orders')]
    #[OA\Parameter(name: 'Idempotency-Key', description: 'Client-generated key (max 64 chars); repeating it returns the original order with 200', in: 'header', required: false, schema: new OA\Schema(type: 'string', maxLength: 64))]
    #[OA\RequestBody(content: new Model(type: CreateOrderRequest::class))]
    #[OA\Response(response: 201, description: 'Order created', content: new Model(type: OrderDetailResponse::class))]
    #[OA\Response(response: 200, description: 'Idempotent replay: the order created by an earlier request with the same key', content: new Model(type: OrderDetailResponse::class))]
    #[OA\Response(response: 404, description: 'Ticket type not found')]
    #[OA\Response(response: 409, description: 'Sold out, sales closed, ticket type disabled or coupon exhausted')]
    #[OA\Response(response: 422, description: 'Validation failed, or invalid coupon/discount')]
    public function createOrder(
        Request $httpRequest,
        #[MapRequestPayload] CreateOrderRequest $request,
    ): JsonResponse {
        try {
            $result = $this->registerOrderService->create($request, $httpRequest->headers->get('Idempotency-Key'));
        } catch (RegisterOrderException $e) {
            return $this->json(['message' => $e->getMessage()], $e->httpStatus);
        } catch (UniqueConstraintViolationException) {
            return $this->json(['message' => 'A request with this Idempotency-Key is already being processed.'], Response::HTTP_CONFLICT);
        } catch (\RuntimeException $e) {
            // Stock, sales window, disabled ticket type and exhausted coupon all surface as RuntimeException.
            $this->logger->warning('Register order refused', ['reason' => $e->getMessage()]);

            return $this->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(
            OrderDetailResponse::fromEntity($result->order),
            $result->created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    #[Route('/orders', name: 'order_list', methods: ['GET'])]
    #[IsGranted('ROLE_REGISTER')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Orders')]
    #[OA\Parameter(name: 'search', description: 'Case-insensitive match on (part of) the order number or the customer first/last name; every word must match', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 64))]
    #[OA\Parameter(name: 'eventId', description: 'Only orders containing a ticket type of this event (UUID)', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'status', description: 'Only orders with one of these statuses (e.g. status[]=pending&status[]=completed)', in: 'query', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', enum: ['pending', 'processing', 'completed', 'cancelled', 'failed', 'refunded'])))]
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
    public function listOrders(#[MapQueryString] OrderListQuery $query = new OrderListQuery()): JsonResponse
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

    #[Route('/orders/{orderNumber}', name: 'order_detail', methods: ['GET'])]
    #[IsGranted('ROLE_REGISTER')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Orders')]
    #[OA\Response(response: 200, description: 'Order detail', content: new Model(type: OrderDetailResponse::class))]
    #[OA\Response(response: 404, description: 'Order not found')]
    public function getOrder(string $orderNumber): JsonResponse
    {
        $order = $this->orderRepository->findOneByOrderNumber($orderNumber);

        if ($order === null) {
            throw $this->createNotFoundException("Order {$orderNumber} could not be found");
        }

        return $this->json(OrderDetailResponse::fromEntity($order));
    }

    #[Route('/orders/{orderNumber}/tickets', name: 'order_tickets_list', methods: ['GET'])]
    #[IsGranted('ROLE_REGISTER')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Orders')]
    #[OA\Response(
        response: 200,
        description: 'All tickets of the order, in line-item order',
        content: new Model(type: OrderTicketsResponse::class),
    )]
    #[OA\Response(response: 404, description: 'Order not found')]
    public function listTickets(string $orderNumber): JsonResponse
    {
        $order = $this->orderRepository->findOneByOrderNumber($orderNumber);

        if ($order === null) {
            throw $this->createNotFoundException("Order {$orderNumber} could not be found");
        }

        return $this->json(OrderTicketsResponse::fromEntities(
            $order,
            $this->ticketRepository->findByOrder($order),
        ));
    }

    #[Route('/orders/{orderNumber}', name: 'order_cancel', methods: ['DELETE'])]
    #[IsGranted('ROLE_REGISTER')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Orders')]
    #[OA\Response(response: 200, description: 'The cancelled order', content: new Model(type: OrderDetailResponse::class))]
    #[OA\Response(response: 404, description: 'Order not found')]
    #[OA\Response(response: 409, description: 'Not a register order, already refunded/failed, or tickets already checked in')]
    public function cancelOrder(string $orderNumber, #[CurrentUser] User $user): JsonResponse
    {
        $order = $this->orderRepository->findOneByOrderNumber($orderNumber);

        if ($order === null) {
            throw $this->createNotFoundException("Order {$orderNumber} could not be found");
        }

        try {
            $this->orderCancellationService->cancel($order, $user->name !== '' ? $user->name : $user->email);
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(OrderDetailResponse::fromEntity($order));
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
