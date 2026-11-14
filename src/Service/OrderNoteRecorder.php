<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderNote;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Appends an audit entry (OrderNote) to an order's history. Every action that
 * changes an order must record one, so the backoffice shows the full trail.
 */
final class OrderNoteRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function record(Order $order, string $content): void
    {
        $note = new OrderNote($order, $content);
        $order->notes->add($note);
        $this->entityManager->persist($note);
        $this->entityManager->flush();
    }
}
