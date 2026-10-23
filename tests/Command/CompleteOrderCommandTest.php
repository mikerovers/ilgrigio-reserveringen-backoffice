<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\Payment;
use App\Enum\OrderStatus;
use App\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CompleteOrderCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
        $this->tester = new CommandTester((new Application($kernel))->find('app:orders:complete'));
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM order_note WHERE order_id IN (SELECT id FROM customer_order WHERE order_number LIKE :p)',
            ['p' => 'CMD-COMPLETE-%'],
        );
        $this->em->getConnection()->executeStatement(
            'DELETE FROM payment WHERE order_id IN (SELECT id FROM customer_order WHERE order_number LIKE :p)',
            ['p' => 'CMD-COMPLETE-%'],
        );
        $this->em->getConnection()->executeStatement(
            'DELETE FROM customer_order WHERE order_number LIKE :p',
            ['p' => 'CMD-COMPLETE-%'],
        );
        $this->em->getConnection()->executeStatement(
            'DELETE FROM customer WHERE email LIKE :p',
            ['p' => 'CMD-COMPLETE-%@example.com'],
        );
        $this->em->clear();
    }

    public function testUnknownOrderFails(): void
    {
        $status = $this->tester->execute(['order-number' => 'DOES-NOT-EXIST']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('could not be found', $this->tester->getDisplay());
    }

    public function testPendingOrderIsCompletedAndFulfilled(): void
    {
        $order = $this->createOrder('CMD-COMPLETE-PENDING');
        $payment = new Payment($order, $order->totalGross);
        $payment->status = PaymentStatus::OPEN;
        $payment->method = 'ideal';
        $order->payment = $payment;
        $this->em->persist($payment);
        $this->em->flush();

        $status = $this->tester->execute(['order-number' => $order->orderNumber]);

        self::assertSame(Command::SUCCESS, $status);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Order::class)->findOneBy(['orderNumber' => $order->orderNumber]);

        self::assertNotNull($reloaded);
        self::assertSame(OrderStatus::COMPLETED, $reloaded->status);
        self::assertNotNull($reloaded->completedAt);
        self::assertNotNull($reloaded->paidAt);
        self::assertNotNull($reloaded->payment);
        self::assertSame(PaymentStatus::PAID, $reloaded->payment->status);
        self::assertNotNull($reloaded->payment->paidAt);
    }

    public function testCancelledOrderIsRefused(): void
    {
        $order = $this->createOrder('CMD-COMPLETE-CANCELLED');
        $order->status = OrderStatus::CANCELLED;
        $this->em->flush();

        $status = $this->tester->execute(['order-number' => $order->orderNumber]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('cannot be completed', $this->tester->getDisplay());
    }

    private function createOrder(string $orderNumber): Order
    {
        $customer = new Customer($orderNumber . '@example.com', 'Test', 'Klant');

        $order = new Order($orderNumber, $customer, 900);
        $order->status = OrderStatus::PENDING;
        $order->subtotalNet = Money::EUR(1000);
        $order->tax = Money::EUR(90);
        $order->discountGross = Money::EUR(0);
        $order->discountTax = Money::EUR(0);
        $order->totalGross = Money::EUR(1090);

        $this->em->persist($customer);
        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }
}
