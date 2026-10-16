<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Repository\SharedStockRepository;
use App\Repository\StockReservationRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Returns (or re-takes) the shared stock an order reserved at checkout.
 * Reservations are created by CheckoutService; this service handles the
 * lifecycle afterwards. All operations are idempotent.
 */
class StockReservationService
{
    public function __construct(
        private StockReservationRepository $reservationRepository,
        private SharedStockRepository $sharedStockRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Give the order's stock back to its pools, once.
     *
     * @return int number of pool reservations actually released
     */
    public function releaseForOrder(Order $order): int
    {
        $released = 0;

        foreach ($this->reservationRepository->findByOrder($order) as $reservation) {
            if (!$this->reservationRepository->markReleased($reservation, $this->clock->now())) {
                continue;
            }

            $this->sharedStockRepository->release($reservation->sharedStock, $reservation->quantity);
            $reservation->releasedAt = $this->clock->now();
            ++$released;

            $this->logger->info('Released stock for order', [
                'order_number' => $order->orderNumber,
                'pool' => $reservation->sharedStock->name,
                'quantity' => $reservation->quantity,
            ]);
        }

        return $released;
    }

    /**
     * Re-take stock that was released before a late payment arrived.
     *
     * @return bool false when at least one pool no longer has capacity (the
     *              order is oversold and needs manual attention)
     */
    public function reacquireForOrder(Order $order): bool
    {
        $ok = true;

        foreach ($this->reservationRepository->findByOrder($order) as $reservation) {
            if ($reservation->releasedAt === null) {
                continue;
            }

            if (!$this->sharedStockRepository->reserve($reservation->sharedStock, $reservation->quantity)) {
                $ok = false;
                $this->logger->error('Late payment but pool has no capacity left', [
                    'order_number' => $order->orderNumber,
                    'pool' => $reservation->sharedStock->name,
                    'quantity' => $reservation->quantity,
                ]);

                continue;
            }

            $this->reservationRepository->markHeld($reservation);
            $reservation->releasedAt = null;
        }

        return $ok;
    }
}
