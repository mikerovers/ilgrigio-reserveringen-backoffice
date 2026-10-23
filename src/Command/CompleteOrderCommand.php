<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Enum\PaymentStatus;
use App\Event\OrderCompletedEvent;
use App\Event\PaymentStatusChangedEvent;
use App\Repository\OrderRepository;
use App\Service\StockReservationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Completes an order by its order number: marks it paid/completed and runs
 * fulfilment (tickets, confirmation email, PDF) exactly like the payment
 * webhook would.
 */
#[AsCommand(
    name: 'app:orders:complete',
    description: 'Completes an order by order number (issues tickets and sends the confirmation email)',
)]
final class CompleteOrderCommand extends Command
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly StockReservationService $stockReservationService,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('order-number', InputArgument::REQUIRED, 'The order number to complete')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-run fulfilment for an already completed order');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $orderNumber */
        $orderNumber = trim((string) $input->getArgument('order-number'));
        $force = (bool) $input->getOption('force');

        if ($orderNumber === '') {
            $io->error('An order number is required.');

            return Command::FAILURE;
        }

        $order = $this->orderRepository->findOneByOrderNumber($orderNumber);

        if ($order === null) {
            $io->error(sprintf('Order "%s" could not be found.', $orderNumber));

            return Command::FAILURE;
        }

        if (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::REFUNDED], true)) {
            $io->error(sprintf(
                'Order %s cannot be completed (status: %s).',
                $order->orderNumber,
                $order->status->value,
            ));

            return Command::FAILURE;
        }

        if ($order->status === OrderStatus::COMPLETED) {
            if (!$force) {
                $io->warning(sprintf(
                    'Order %s is already completed; use --force to re-run fulfilment.',
                    $order->orderNumber,
                ));

                return Command::SUCCESS;
            }

            $this->eventDispatcher->dispatch(new OrderCompletedEvent($order));

            $io->success(sprintf('Fulfilment re-run for order %s.', $order->orderNumber));

            return Command::SUCCESS;
        }

        $now = $this->clock->now();
        $previousOrderStatus = $order->status;
        $wasFailed = $previousOrderStatus === OrderStatus::FAILED;

        // A manual completion counts as paid: mark the payment accordingly.
        $payment = $order->payment;
        if ($payment !== null && $payment->status !== PaymentStatus::PAID) {
            $previousStatus = $payment->status;
            $payment->status = PaymentStatus::PAID;
            $payment->paidAt ??= $now;

            $this->eventDispatcher->dispatch(
                new PaymentStatusChangedEvent($order, $previousStatus, PaymentStatus::PAID),
            );
        }

        $order->completeNow($now);

        // Stock was handed back when the payment failed; take it again.
        if ($wasFailed && !$this->stockReservationService->reacquireForOrder($order)) {
            $io->warning(sprintf(
                'Order %s is oversold: a shared stock pool no longer has capacity.',
                $order->orderNumber,
            ));
        }

        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new OrderCompletedEvent($order));

        $this->logger->info('Order completed manually via command', [
            'order_number' => $order->orderNumber,
            'previous_status' => $previousOrderStatus->value,
        ]);

        $io->success(sprintf('Order %s completed.', $order->orderNumber));

        return Command::SUCCESS;
    }
}
