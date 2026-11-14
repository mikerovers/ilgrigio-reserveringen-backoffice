<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\OrderNote;
use App\Entity\SharedStock;
use App\Entity\StockReservation;
use App\Entity\TicketType;
use App\Enum\OrderStatus;
use App\Enum\TicketStatus;
use App\Repository\EventRepository;
use App\Repository\SharedStockRepository;
use App\Repository\StockReservationRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Moves a completed order to another show of the same category. Every ticket
 * type of the order is mapped to a ticket type of the new show. Prices paid and
 * ticket codes stay as they are, so tickets already handed out keep scanning.
 * Staff may overbook: the new show's stock is taken without a capacity guard.
 */
final class OrderRebookingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EventRepository $eventRepository,
        private SharedStockRepository $sharedStockRepository,
        private StockReservationRepository $reservationRepository,
        private StockReservationService $stockReservationService,
        private CheckInBroadcaster $broadcaster,
        private TicketRepository $ticketRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return Event|null the show the order is booked on; null when it has none or spans several shows
     */
    public function sourceEvent(Order $order): ?Event
    {
        $event = null;
        foreach ($order->lineItems as $line) {
            $lineEvent = $line->ticketType->event;
            if ($event !== null && $event->id->toRfc4122() !== $lineEvent->id->toRfc4122()) {
                return null;
            }
            $event = $lineEvent;
        }

        return $event;
    }

    /**
     * @return Event[] shows the order may move to
     */
    public function targets(Order $order): array
    {
        $source = $this->sourceEvent($order);

        return $source === null ? [] : $this->eventRepository->findRebookTargets($source, $this->clock->now());
    }

    /**
     * Distinct ticket types of the order with their quantity, in line order.
     *
     * @return list<array{ticketType: TicketType, name: string, quantity: int}>
     */
    public function sourceTypes(Order $order): array
    {
        $rows = [];
        foreach ($order->lineItems as $line) {
            $id = $line->ticketType->id->toRfc4122();
            $rows[$id] ??= ['ticketType' => $line->ticketType, 'name' => $line->name, 'quantity' => 0];
            $rows[$id]['quantity'] += $line->quantity;
        }

        return array_values($rows);
    }

    /**
     * Suggest a target ticket type for each source type by name (case, accents
     * and spacing ignored). Types without a match are left out.
     *
     * @return array<string, string> source ticket type id => target ticket type id
     */
    public function suggestMapping(Order $order, Event $target): array
    {
        $byName = [];
        foreach ($target->ticketTypes as $type) {
            if (!$type->disabled) {
                $byName[self::normalise($type->name)] ??= $type->id->toRfc4122();
            }
        }

        $map = [];
        foreach ($this->sourceTypes($order) as $row) {
            $match = $byName[self::normalise($row['ticketType']->name)] ?? null;
            if ($match !== null) {
                $map[$row['ticketType']->id->toRfc4122()] = $match;
            }
        }

        return $map;
    }

    /**
     * @param array<string, string> $mapping source ticket type id => target ticket type id
     *
     * @throws \DomainException when the order may not be moved (maps to 409/422)
     */
    public function rebook(Order $order, Event $target, array $mapping, string $by): void
    {
        $source = $this->sourceEvent($order);
        if ($order->status !== OrderStatus::COMPLETED) {
            throw new \DomainException('Alleen voltooide bestellingen kunnen worden omgeboekt.');
        }
        if ($source === null) {
            throw new \DomainException('Deze bestelling hoort niet bij één show en kan niet worden omgeboekt.');
        }
        if ($source->id->toRfc4122() === $target->id->toRfc4122()) {
            throw new \DomainException('De bestelling staat al op deze show.');
        }
        if (!in_array($target->id->toRfc4122(), array_map(static fn (Event $e): string => $e->id->toRfc4122(), $this->targets($order)), true)) {
            throw new \DomainException('Deze show is niet beschikbaar om naar om te boeken (andere categorie of al geweest).');
        }
        foreach ($order->tickets as $ticket) {
            if ($ticket->status === TicketStatus::CHECKED_IN) {
                throw new \DomainException('Er zijn tickets van deze bestelling al ingecheckt.');
            }
        }

        $targetTypes = [];
        foreach ($target->ticketTypes as $type) {
            $targetTypes[$type->id->toRfc4122()] = $type;
        }
        $resolved = [];
        foreach ($this->sourceTypes($order) as $row) {
            $sourceId = $row['ticketType']->id->toRfc4122();
            $type = $targetTypes[$mapping[$sourceId] ?? ''] ?? null;
            if ($type === null) {
                throw new \DomainException(sprintf('Kies een tickettype voor "%s".', $row['name']));
            }
            if ($type->disabled) {
                throw new \DomainException(sprintf('Tickettype "%s" is niet beschikbaar.', $type->name));
            }
            $resolved[$sourceId] = $type;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->stockReservationService->releaseForOrder($order);

            $demand = [];
            $pools = [];
            foreach ($order->lineItems as $line) {
                $type = $resolved[$line->ticketType->id->toRfc4122()];
                $line->ticketType = $type;
                $line->name = $type->name;
                if ($type->sharedStock !== null) {
                    $poolId = $type->sharedStock->id->toRfc4122();
                    $pools[$poolId] = $type->sharedStock;
                    $demand[$poolId] = ($demand[$poolId] ?? 0) + $line->quantity;
                }
            }

            foreach ($order->tickets as $ticket) {
                $type = $resolved[$ticket->ticketType->id->toRfc4122()];
                $ticket->ticketType = $type;
                $ticket->event = $target;
                $ticket->ticketName = $type->name;
                $ticket->eventNameSnapshot = $target->name;
                $ticket->eventDateTimeSnapshot = $target->startsAt;
                $ticket->eventDoorsOpenSnapshot = $target->doorsOpenAt;
            }

            ksort($pools);
            foreach ($pools as $poolId => $pool) {
                $this->takeStock($order, $pool, $demand[$poolId]);
            }

            $note = new OrderNote($order, sprintf(
                'Omgeboekt door %s van "%s" (%s) naar "%s" (%s).',
                $by,
                $source->name,
                VenueTime::toLocal($source->startsAt)->format('d-m-Y H:i'),
                $target->name,
                VenueTime::toLocal($target->startsAt)->format('d-m-Y H:i'),
            ));
            $order->notes->add($note);
            $this->entityManager->persist($note);
            $this->entityManager->flush();

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        $now = $this->clock->now();
        foreach ([$source, $target] as $event) {
            $this->broadcaster->changed($event, $this->ticketRepository->countByStatusForEvent($event), $now);
        }

        $this->logger->info('Register order rebooked', [
            'order_number' => $order->orderNumber,
            'from_event' => $source->name,
            'to_event' => $target->name,
            'rebooked_by' => $by,
        ]);
    }

    private function takeStock(Order $order, SharedStock $pool, int $quantity): void
    {
        $this->sharedStockRepository->take($pool, $quantity);

        $existing = null;
        foreach ($this->reservationRepository->findByOrder($order) as $reservation) {
            if ($reservation->sharedStock->id->toRfc4122() === $pool->id->toRfc4122()) {
                $existing = $reservation;
                break;
            }
        }

        // One row per order and pool: reuse the released row when the pool is unchanged.
        if ($existing !== null) {
            $existing->quantity = $quantity;
            $existing->releasedAt = null;
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE stock_reservation SET quantity = :qty, released_at = NULL WHERE id = :id',
                ['qty' => $quantity, 'id' => $existing->id->toRfc4122()],
            );

            return;
        }

        $this->entityManager->persist(new StockReservation($order, $pool, $quantity, $this->clock->now()));
    }

    private static function normalise(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $ascii = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')?->transliterate($name);

        return (string) preg_replace('/\s+/', ' ', $ascii ?? $name);
    }
}
