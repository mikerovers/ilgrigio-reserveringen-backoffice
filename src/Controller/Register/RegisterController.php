<?php

declare(strict_types=1);

namespace App\Controller\Register;

use App\Entity\Order;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\TicketStatus;
use App\Repository\OrderRepository;
use App\Service\EventSchedule;
use App\Service\MoneyService;
use App\Service\SecurePdfStorageService;
use Money\Money;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(host: '%register_host%')]
#[IsGranted('ROLE_REGISTER')]
final class RegisterController extends AbstractController
{
    #[Route('/', name: 'register_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('register/index.html.twig', ['operator' => $this->operator()]);
    }

    #[Route('/bestellingen', name: 'register_orders', methods: ['GET'])]
    public function orders(): Response
    {
        return $this->render('register/orders.html.twig', ['operator' => $this->operator()]);
    }

    #[Route('/kortingscodes', name: 'register_coupons', methods: ['GET'])]
    public function coupons(): Response
    {
        return $this->render('register/coupons.html.twig', ['operator' => $this->operator()]);
    }

    /**
     * Printable sheet with the order summary and every valid ticket's QR code,
     * packed as many per page as fit. It opens the browser's print dialog itself.
     */
    #[Route('/bestellingen/{orderNumber}/tickets', name: 'register_order_tickets', methods: ['GET'])]
    public function orderTickets(
        string $orderNumber,
        OrderRepository $orders,
        SecurePdfStorageService $pdfService,
        MoneyService $moneyService,
    ): Response {
        $order = $orders->findOneByOrderNumber($orderNumber);
        if ($order === null) {
            throw $this->createNotFoundException(sprintf('Order %s could not be found.', $orderNumber));
        }

        $euro = static fn (Money $money): string => '€ ' . number_format($moneyService->toFloat($money), 2, ',', '.');

        $tickets = $order->status === OrderStatus::COMPLETED ? $this->validTickets($order) : [];
        $ticketViews = [];
        foreach ($tickets as $i => $ticket) {
            $ticketViews[] = [
                'number' => $i + 1,
                'code' => $ticket->ticketCode,
                'name' => $ticket->ticketName,
                'qr' => $pdfService->qrDataUriForTicketCode($ticket->ticketCode),
            ];
        }

        $lines = [];
        foreach ($order->lineItems as $line) {
            $lines[] = ['name' => $line->name, 'quantity' => $line->quantity, 'price' => $euro($line->lineGross)];
        }

        $first = $tickets[0] ?? null;

        return $this->render('register/order_tickets.html.twig', [
            'order' => $order,
            'event_name' => $first?->eventNameSnapshot,
            'event_date' => $first !== null ? EventSchedule::date($first->eventDateTimeSnapshot) : null,
            'lines' => $lines,
            'discount' => $order->discountGross->isZero() ? null : $euro($order->discountGross),
            'tax' => $euro($order->tax),
            'total' => $euro($order->totalGross),
            'ticket_count' => count($ticketViews),
            'tickets' => $ticketViews,
        ]);
    }

    /**
     * @return list<Ticket> issued or checked-in tickets in line-item order (void ones are not printed)
     */
    private function validTickets(Order $order): array
    {
        $tickets = array_values(array_filter(
            $order->tickets->toArray(),
            static fn (Ticket $t): bool => $t->status !== TicketStatus::VOID,
        ));
        usort($tickets, static fn (Ticket $a, Ticket $b): int => [$a->lineItem->id->toRfc4122(), $a->lineIndex]
            <=> [$b->lineItem->id->toRfc4122(), $b->lineIndex]);

        return $tickets;
    }

    private function operator(): ?string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->name : null;
    }
}
