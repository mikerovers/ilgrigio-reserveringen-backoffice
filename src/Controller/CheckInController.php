<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CheckInRequest;
use App\DTO\CheckInResponse;
use App\Entity\Event;
use App\Service\CheckInResult;
use App\Service\CheckInService;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
    #[OA\Response(
        response: 200,
        description: 'Check-in result',
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

        $status = match ($result) {
            CheckInResult::ADMITTED => Response::HTTP_OK,
            CheckInResult::ALREADY_CHECKED_IN, CheckInResult::VOID => Response::HTTP_CONFLICT,
            CheckInResult::NOT_FOUND => Response::HTTP_NOT_FOUND,
        };

        $ticket = $this->checkInService->findTicket($event, $request->ticketCode);

        return $this->json(
            new CheckInResponse(
                result: $result,
                ticketCode: $request->ticketCode,
                ticketName: $ticket?->ticketName,
                checkedInAt: $ticket?->checkedInAt?->format(\DateTimeInterface::ATOM),
            ),
            $status,
        );
    }
}
