<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CheckInRequest;
use App\DTO\CheckInResponse;
use App\DTO\OrderCheckInResponse;
use App\Entity\Event;
use App\Entity\Order;
use App\Service\CheckInResult;
use App\Service\CheckInService;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Door check-in endpoints. The outcome of each attempt (admitted, already checked
 * in, void, not found) is carried in the JSON body; these endpoints always
 * answer 200 once the event or order is resolved.
 */
#[Route('/api', name: 'api_')]
final class CheckInController extends AbstractController
{
    public function __construct(
        private CheckInService $checkInService,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/events/{eventId}/check-in', name: 'event_check_in', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Check-in')]
    #[OA\Response(
        response: 200,
        description: 'Check-in result. Inspect `result` for ADMITTED, ALREADY_CHECKED_IN, VOID or NOT_FOUND.',
        content: new Model(type: CheckInResponse::class),
    )]
    public function checkIn(
        #[MapEntity(mapping: ['eventId' => 'id'])] Event $event,
        #[MapRequestPayload] CheckInRequest $request,
    ): JsonResponse {
        $result = $this->checkInService->checkIn(
            $event,
            $request->ticketCode,
            $this->clock->now(),
        );

        $ticket = $this->checkInService->findTicket($event, $request->ticketCode);

        return $this->json(
            new CheckInResponse(
                result: $result,
                ticketCode: $request->ticketCode,
                ticketName: $ticket?->ticketName,
                checkedInAt: $ticket?->checkedInAt?->format(\DateTimeInterface::ATOM),
            ),
        );
    }

    #[Route('/orders/{orderNumber}/check-in', name: 'order_check_in', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Check-in')]
    #[OA\Response(
        response: 200,
        description: 'Per-ticket check-in results. Issued tickets are admitted; '
            . 'checked-in and void tickets are reported, not changed.',
        content: new Model(type: OrderCheckInResponse::class),
    )]
    #[OA\Response(response: 404, description: 'Order not found')]
    public function checkInOrder(
        #[MapEntity(mapping: ['orderNumber' => 'orderNumber'])] Order $order,
    ): JsonResponse {
        $outcomes = $this->checkInService->checkInOrder($order, $this->clock->now());

        return $this->json(OrderCheckInResponse::fromOutcomes($order, $outcomes));
    }
}
