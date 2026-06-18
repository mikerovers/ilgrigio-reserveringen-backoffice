<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\OrderNote;
use App\Enum\PaymentStatus;
use App\Event\OrderCompletedEvent;
use App\Event\OrderCreatedEvent;
use App\Event\PaymentStatusChangedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Maintains an audit trail on orders by appending an OrderNote whenever a
 * significant lifecycle event occurs.
 */
final class OrderHistoryListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[AsEventListener]
    public function onOrderCreated(OrderCreatedEvent $event): void
    {
        $order = $event->order;

        $lines = [];
        foreach ($order->lineItems as $lineItem) {
            $lines[] = sprintf('%d× %s', $lineItem->quantity, $lineItem->name);
        }

        $total = number_format((int) $order->totalGross->getAmount() / 100, 2, ',', '.');

        $content = sprintf(
            'Bestelling aangemaakt: %s. Totaal: €%s.',
            implode(', ', $lines),
            $total,
        );

        $this->addNote($order, $content);
    }

    #[AsEventListener]
    public function onPaymentStatusChanged(PaymentStatusChangedEvent $event): void
    {
        $order = $event->order;

        if ($event->previousStatus === null) {
            $content = 'Betaling gestart.';
        } else {
            $content = sprintf(
                'Betalingsstatus gewijzigd: %s → %s.',
                $this->translateStatus($event->previousStatus),
                $this->translateStatus($event->newStatus),
            );
        }

        $this->addNote($order, $content);
    }

    #[AsEventListener]
    public function onOrderCompleted(OrderCompletedEvent $event): void
    {
        $order = $event->order;

        // For paid orders the PaymentStatusChangedEvent (PAID) already records completion.
        // Only add a note for zero-total orders that bypass payment entirely.
        if ($order->payment === null) {
            $this->addNote($order, 'Bestelling gratis voltooid (geen betaling vereist).');
        }
    }

    private function addNote(mixed $order, string $content): void
    {
        $note = new OrderNote($order, $content);
        $order->notes->add($note);
        $this->entityManager->persist($note);
        $this->entityManager->flush();
    }

    private function translateStatus(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::OPEN => 'open',
            PaymentStatus::PENDING => 'in behandeling',
            PaymentStatus::PAID => 'betaald',
            PaymentStatus::CANCELED => 'geannuleerd',
            PaymentStatus::EXPIRED => 'verlopen',
            PaymentStatus::FAILED => 'mislukt',
        };
    }
}
