<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Mercure\CheckInPublisher;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Grants the calling scanner a Mercure subscriber cookie for one event's update
 * topic. Browsers' EventSource cannot send an Authorization header, so the hub
 * reads the subscriber JWT from this cookie (same origin as the scanner page).
 */
#[Route('/api', name: 'api_')]
final class ScannerMercureController extends AbstractController
{
    public function __construct(
        private Authorization $authorization,
    ) {
    }

    #[Route('/events/{eventId}/mercure-subscription', name: 'scanner_mercure_subscription', methods: ['POST'])]
    #[IsGranted('ROLE_CHECK_IN')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Check-in')]
    #[OA\Response(response: 204, description: 'Subscriber cookie set for the event updates topic')]
    public function subscribe(
        Request $request,
        #[MapEntity(mapping: ['eventId' => 'id'])] Event $event,
    ): Response {
        $this->authorization->setCookie(
            $request,
            [CheckInPublisher::topicFor((string) $event->id)],
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
