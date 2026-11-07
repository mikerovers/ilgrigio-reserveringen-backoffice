<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Ticket;
use App\Enum\TicketStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    /**
     * Single-index lookup for door scanning.
     */
    public function findOneForCheckIn(Event $event, string $ticketCode): ?Ticket
    {
        return $this->findOneBy(['event' => $event, 'ticketCode' => $ticketCode]);
    }

    /**
     * Atomically admit one ticket: the WHERE guard makes the status check and the
     * write a single statement, so two scanners cannot both admit the same ticket.
     *
     * @return bool true only when this call moved the ticket from ISSUED to CHECKED_IN
     */
    public function admitIfIssued(Event $event, string $ticketCode, \DateTimeImmutable $now, ?string $by): bool
    {
        $affected = (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE ticket
                SET status = :checked_in, checked_in_at = :at, checked_in_by = :by
              WHERE event_id = :event AND ticket_code = :code AND status = :issued',
            [
                'checked_in' => TicketStatus::CHECKED_IN->value,
                'at' => $this->toUtcString($now),
                'by' => $by,
                'event' => $event->id->toRfc4122(),
                'code' => $ticketCode,
                'issued' => TicketStatus::ISSUED->value,
            ],
        );

        return $affected === 1;
    }

    /**
     * Admit every ISSUED ticket of an order in one statement. Checked-in and void
     * tickets are left untouched.
     *
     * @return string[] ids (RFC 4122) of the tickets admitted by this call
     */
    public function admitIssuedForOrder(Order $order, \DateTimeImmutable $now, ?string $by): array
    {
        return array_map(
            'strval',
            $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'UPDATE ticket
                    SET status = :checked_in, checked_in_at = :at, checked_in_by = :by
                  WHERE order_id = :order AND status = :issued
                  RETURNING id',
                [
                    'checked_in' => TicketStatus::CHECKED_IN->value,
                    'at' => $this->toUtcString($now),
                    'by' => $by,
                    'order' => $order->id->toRfc4122(),
                    'issued' => TicketStatus::ISSUED->value,
                ],
            ),
        );
    }

    /**
     * Atomically void every ISSUED ticket of an order. Checked-in tickets are never
     * overwritten, even if they were scanned after the caller read the order.
     */
    public function voidIssuedForOrder(Order $order): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE ticket SET status = :void WHERE order_id = :order AND status = :issued',
            [
                'void' => TicketStatus::VOID->value,
                'order' => $order->id->toRfc4122(),
                'issued' => TicketStatus::ISSUED->value,
            ],
        );
    }

    /**
     * Number of tickets of an order that are already checked in.
     */
    public function countCheckedInForOrder(Order $order): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ticket WHERE order_id = :order AND status = :checked_in',
            [
                'order' => $order->id->toRfc4122(),
                'checked_in' => TicketStatus::CHECKED_IN->value,
            ],
        );
    }

    /**
     * Valid (non-void) and checked-in ticket counts of one event, per order.
     *
     * @param string[] $orderIds RFC 4122 order ids
     * @return array<string, array{total: int, checkedIn: int}> keyed by order id
     */
    public function countsPerOrderForEvent(Event $event, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT order_id, COUNT(*) AS total, COUNT(*) FILTER (WHERE status = :checked_in) AS checked_in
               FROM ticket
              WHERE event_id = :event AND status <> :void AND order_id IN (:orders)
              GROUP BY order_id',
            [
                'event' => $event->id->toRfc4122(),
                'checked_in' => TicketStatus::CHECKED_IN->value,
                'void' => TicketStatus::VOID->value,
                'orders' => $orderIds,
            ],
            ['orders' => ArrayParameterType::STRING],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['order_id']] = ['total' => (int) $row['total'], 'checkedIn' => (int) $row['checked_in']];
        }

        return $counts;
    }

    /**
     * Ticket counts of an event per status, for the scanner counters.
     * expected = issued + checkedIn (void tickets do not count).
     *
     * @return array{issued: int, checkedIn: int, void: int}
     */
    public function countByStatusForEvent(Event $event): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT status, COUNT(*) FROM ticket WHERE event_id = :event GROUP BY status',
            ['event' => $event->id->toRfc4122()],
        );

        return [
            'issued' => (int) ($rows[TicketStatus::ISSUED->value] ?? 0),
            'checkedIn' => (int) ($rows[TicketStatus::CHECKED_IN->value] ?? 0),
            'void' => (int) ($rows[TicketStatus::VOID->value] ?? 0),
        ];
    }

    /**
     * Tickets of an event admitted by one scanner user, newest scan first.
     *
     * @return Ticket[]
     */
    public function findCheckedInByUser(Event $event, string $by, int $limit, int $offset = 0): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('tt', 'o')
            ->join('t.ticketType', 'tt')
            ->join('t.order', 'o')
            ->where('t.event = :event')
            ->andWhere('t.status = :status')
            ->andWhere('t.checkedInBy = :by')
            ->setParameter('event', $event->id, 'uuid')
            ->setParameter('status', TicketStatus::CHECKED_IN)
            ->setParameter('by', $by)
            ->orderBy('t.checkedInAt', SortDirection::Descending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countCheckedInByUser(Event $event, string $by): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ticket WHERE event_id = :event AND status = :status AND checked_in_by = :by',
            [
                'event' => $event->id->toRfc4122(),
                'status' => TicketStatus::CHECKED_IN->value,
                'by' => $by,
            ],
        );
    }

    private function toUtcString(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * All tickets issued for an order, in line-item order.
     *
     * @return Ticket[]
     */
    public function findByOrder(Order $order): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.order = :order')
            ->setParameter('order', $order->id, 'uuid')
            ->orderBy('t.lineItem', SortDirection::Ascending)
            ->addOrderBy('t.lineIndex', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
