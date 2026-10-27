<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Ticket;
use App\Enum\TicketStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
