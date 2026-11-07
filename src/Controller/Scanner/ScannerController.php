<?php

declare(strict_types=1);

namespace App\Controller\Scanner;

use App\DTO\EventStatsResponse;
use App\DTO\ScanRequest;
use App\DTO\ScanResponse;
use App\DTO\ScannerOrderResponse;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Repository\OrderRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use App\Service\CheckInBroadcaster;
use App\Service\CheckInResult;
use App\Service\CheckInService;
use App\Service\VenueTime;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Door scanner app (scanbalie), served on the scanner host only. Session
 * authenticated; every action needs ROLE_CHECK_IN.
 */
#[Route(host: '%scanner_host%')]
#[IsGranted('ROLE_CHECK_IN')]
final class ScannerController extends AbstractController
{
    /** Shows whose doors open within a day (default). */
    public const SELECTION_DOORS = 'doors';
    /** Every show that starts today (venue time). */
    public const SELECTION_TODAY = 'today';

    private const SCANS_PER_PAGE = 25;

    public function __construct(
        private EventRepository $eventRepository,
        private TicketRepository $ticketRepository,
        private OrderRepository $orderRepository,
        private UserRepository $userRepository,
        private CheckInService $checkInService,
        private CheckInBroadcaster $broadcaster,
        private HubInterface $hub,
        private ClockInterface $clock,
        #[Autowire('%mercure_enabled%')]
        private bool $mercureEnabled = false,
        #[Autowire('%scanner_event_selection%')]
        private string $eventSelection = self::SELECTION_DOORS,
    ) {
    }

    #[Route('/', name: 'scanner_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $now = $this->clock->now();
        $events = $this->selectableEvents($now);
        $event = $this->selectEvent($events, (string) $request->query->get('event'));

        return $this->render('scanner/index.html.twig', [
            'events' => $events,
            'event' => $event,
            'info' => $event !== null ? $this->eventInfo($event, $now) : null,
            'stats' => $event !== null
                ? EventStatsResponse::fromCounts($this->ticketRepository->countByStatusForEvent($event))
                : null,
            'operator' => $this->operatorName(),
            'mercureEnabled' => $this->mercureEnabled,
        ]);
    }

    #[Route('/events/{id}/stats', name: 'scanner_stats', methods: ['GET'])]
    public function stats(#[MapEntity(mapping: ['id' => 'id'])] Event $event): JsonResponse
    {
        return $this->json(EventStatsResponse::fromCounts($this->ticketRepository->countByStatusForEvent($event)));
    }

    #[Route('/events/{id}/scan', name: 'scanner_scan', methods: ['POST'])]
    public function scan(
        #[MapEntity(mapping: ['id' => 'id'])] Event $event,
        #[MapRequestPayload] ScanRequest $payload,
    ): JsonResponse {
        $now = $this->clock->now();
        $code = trim($payload->ticketCode);
        $by = $this->getUser()?->getUserIdentifier();

        $result = $this->checkInService->checkIn($event, $code, $now, $by);
        $ticket = $this->checkInService->findTicket($event, $code);
        $counts = $this->ticketRepository->countByStatusForEvent($event);

        if ($result === CheckInResult::ADMITTED) {
            $this->broadcaster->admitted($event, $counts, $payload->scanId, $by, $now);
        }

        $response = ScanResponse::create($result, $code, $ticket, EventStatsResponse::fromCounts($counts));
        if ($response->checkedInBy !== null) {
            $response->checkedInBy = $this->displayName($response->checkedInBy);
        }

        return $this->json($response);
    }

    /**
     * The order behind a scanned ticket with all its tickets, fetched only when
     * the balie opens the order popup.
     */
    #[Route('/events/{id}/orders/{orderNumber}', name: 'scanner_order', methods: ['GET'])]
    public function order(#[MapEntity(mapping: ['id' => 'id'])] Event $event, string $orderNumber): JsonResponse
    {
        $order = $this->orderRepository->findOneByOrderNumber($orderNumber);
        $tickets = $order !== null ? $this->ticketRepository->findByOrder($order) : [];

        $forThisShow = array_filter($tickets, static fn ($t) => $t->event->id->equals($event->id));
        if ($order === null || $forThisShow === []) {
            throw $this->createNotFoundException();
        }

        $response = $this->json(ScannerOrderResponse::create($order, $event, $tickets));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Mercure connection details for the page: the hub URL and a short-lived
     * subscriber token limited to this event's topic. EventSource cannot set
     * headers and the hub is another origin, so the token travels as the
     * `authorization` query parameter; the page fetches a fresh one whenever it
     * (re)connects.
     */
    #[Route('/events/{id}/mercure-token', name: 'scanner_mercure_token', methods: ['GET'])]
    public function mercureToken(#[MapEntity(mapping: ['id' => 'id'])] Event $event): JsonResponse
    {
        $topic = CheckInBroadcaster::topic($event);
        // Not Authorization::createCookie(): that insists on a hub on the same
        // second-level domain, and this token is passed in the URL, not a cookie.
        $token = $this->hub->getFactory()?->create(
            [new Grant([Grant::ACTION_SUBSCRIBE], [$topic])],
            ['exp' => new \DateTimeImmutable('+1 hour')],
        ) ?? throw new \LogicException('The Mercure hub has no token factory.');

        $response = $this->json([
            'hub' => $this->hub->getPublicUrl(),
            'topic' => $topic,
            'token' => $token,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/scans', name: 'scanner_scans', methods: ['GET'])]
    public function scans(Request $request): Response
    {
        $now = $this->clock->now();
        $events = $this->selectableEvents($now);
        $event = $this->selectEvent($events, (string) $request->query->get('event'));
        $by = $this->getUser()?->getUserIdentifier() ?? '';
        $page = max(1, $request->query->getInt('page', 1));

        $total = $event !== null ? $this->ticketRepository->countCheckedInByUser($event, $by) : 0;
        $tickets = $event !== null
            ? $this->ticketRepository->findCheckedInByUser($event, $by, self::SCANS_PER_PAGE, ($page - 1) * self::SCANS_PER_PAGE)
            : [];

        return $this->render('scanner/scans.html.twig', [
            'events' => $events,
            'event' => $event,
            'info' => $event !== null ? $this->eventInfo($event, $now) : null,
            'tickets' => $tickets,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::SCANS_PER_PAGE)),
            'operator' => $this->operatorName(),
        ]);
    }

    /**
     * @return Event[]
     */
    private function selectableEvents(\DateTimeImmutable $now): array
    {
        return match ($this->eventSelection) {
            self::SELECTION_TODAY => $this->eventRepository->findStartingToday($now),
            default => $this->eventRepository->findScannable($now),
        };
    }

    /**
     * @param Event[] $events
     */
    private function selectEvent(array $events, string $requestedId): ?Event
    {
        foreach ($events as $candidate) {
            if ($candidate->id->toRfc4122() === $requestedId) {
                return $candidate;
            }
        }

        return $events[0] ?? null;
    }

    /**
     * @return array{day: string, date: string, doorsOpen: ?string, starts: string, location: ?string}
     */
    private function eventInfo(Event $event, \DateTimeImmutable $now): array
    {
        $start = VenueTime::toLocal($event->startsAt);
        $today = VenueTime::toLocal($now)->format('Y-m-d');
        $date = new \IntlDateFormatter('nl_NL', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, VenueTime::TIMEZONE, null, 'd MMMM y');

        $day = match ($start->format('Y-m-d')) {
            $today => 'Vandaag',
            VenueTime::toLocal($now)->modify('+1 day')->format('Y-m-d') => 'Morgen',
            default => (new \IntlDateFormatter('nl_NL', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, VenueTime::TIMEZONE, null, 'EEEE'))->format($start),
        };

        return [
            'day' => ucfirst((string) $day),
            'date' => (string) $date->format($start),
            'doorsOpen' => $event->doorsOpenAt !== null ? VenueTime::toLocal($event->doorsOpenAt)->format('H:i') : null,
            'starts' => $start->format('H:i'),
            'location' => $event->location,
        ];
    }

    private function operatorName(): string
    {
        $user = $this->getUser();

        return $user instanceof User && $user->name !== '' ? $user->name : (string) $user?->getUserIdentifier();
    }

    /** checked_in_by stores the user identifier (email); show the display name instead. */
    private function displayName(string $identifier): string
    {
        $user = $this->userRepository->findOneBy(['email' => $identifier]);

        return $user !== null && $user->name !== '' ? $user->name : $identifier;
    }
}
