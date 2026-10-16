<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Enum\PaymentStatus;
use App\Service\StockReservationService;
use Doctrine\ORM\EntityManagerInterface;
use Omnipay\Mollie\Gateway;
use Omnipay\Mollie\Message\Response\FetchTransactionResponse;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:orders:expire-pending',
    description: 'Fails pending orders older than the reservation TTL and returns their stock',
)]
final class ExpirePendingOrdersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StockReservationService $stockReservationService,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $mollieApiKey,
        private readonly int $reservationTtlMinutes,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cutoff = $this->clock->now()->modify(sprintf('-%d minutes', $this->reservationTtlMinutes));

        /** @var Order[] $orders */
        $orders = $this->entityManager->createQuery(
            'SELECT o FROM ' . Order::class . ' o WHERE o.status = :status AND o.createdAt < :cutoff'
        )
            ->setParameter('status', OrderStatus::PENDING)
            ->setParameter('cutoff', $cutoff)
            ->getResult();

        $expired = 0;

        foreach ($orders as $order) {
            try {
                if ($this->expire($order)) {
                    ++$expired;
                }
            } catch (\Throwable $e) {
                // Keep going: one unreachable payment must not block the rest.
                $this->logger->error('Failed to expire pending order', [
                    'order_number' => $order->orderNumber,
                    'error' => $e->getMessage(),
                ]);
                $io->warning(sprintf('Order %s skipped: %s', $order->orderNumber, $e->getMessage()));
            }
        }

        $io->success(sprintf('Expired %d of %d stale pending order(s).', $expired, count($orders)));

        return Command::SUCCESS;
    }

    private function expire(Order $order): bool
    {
        $payment = $order->payment;

        // A late PAID must win: ask Mollie before giving the stock away. The
        // webhook consumer will complete the order and re-take the stock.
        if ($payment !== null && $payment->molliePaymentId !== null) {
            $gateway = new Gateway();
            $gateway->setApiKey($this->mollieApiKey);

            /** @var FetchTransactionResponse $response */
            $response = $gateway->fetchTransaction([
                'transactionReference' => $payment->molliePaymentId,
            ])->send();

            $status = PaymentStatus::from($response->getStatus());

            if ($status === PaymentStatus::PAID || $status === PaymentStatus::PENDING) {
                return false;
            }

            $payment->status = $status->isFailed() ? $status : PaymentStatus::EXPIRED;
        }

        $order->status = OrderStatus::FAILED;
        $this->stockReservationService->releaseForOrder($order);
        $this->entityManager->flush();

        $this->logger->info('Expired pending order', ['order_number' => $order->orderNumber]);

        return true;
    }
}
