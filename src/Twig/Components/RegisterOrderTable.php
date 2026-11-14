<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\DTO\OrderListQuery;
use App\DTO\PageQuery;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\OrderNote;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Event\OrderCompletedEvent;
use App\Repository\OrderNoteRepository;
use App\Repository\OrderRepository;
use App\Service\MoneyService;
use App\Service\OrderCancellationService;
use App\Service\OrderNoteRecorder;
use App\Service\OrderRebookingService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\Pagination\LiveComponent\ComponentWithPaginationTrait;
use Symfony\UX\Pagination\Pagination;
use Symfony\UX\Pagination\PaginationBuilder;
use Symfony\UX\Pagination\PaginatorInterface;

/**
 * Paginated, searchable order table of the register app with its row actions
 * (notes modal, cancel, resend the order mail).
 *
 * Live components are reachable on every host under /_components, so the class
 * carries its own IsGranted: the register firewall alone does not protect it.
 */
#[AsLiveComponent(name: 'RegisterOrderTable', template: 'components/register/order_table.html.twig')]
#[IsGranted('ROLE_REGISTER')]
final class RegisterOrderTable
{
    use DefaultActionTrait;
    use ComponentWithPaginationTrait;

    public const PER_PAGE = 10;
    private const MAX_SEARCH_LENGTH = 64;
    private const MAX_NOTE_LENGTH = 5000;

    #[LiveProp(writable: true, onUpdated: 'resetPage')]
    public string $search = '';

    /** Order number whose notes modal is open. */
    #[LiveProp]
    public ?string $notesFor = null;

    /** Order number waiting for a cancel confirmation. */
    #[LiveProp]
    public ?string $cancelFor = null;

    /** Order number whose rebook (omboeken) modal is open. */
    #[LiveProp]
    public ?string $rebookFor = null;

    /** Id of the show picked in the rebook modal. */
    #[LiveProp(writable: true, onUpdated: 'autoMap')]
    public string $rebookEvent = '';

    /** @var array<string, string> source ticket type id => target ticket type id */
    #[LiveProp(writable: true)]
    public array $rebookMap = [];

    #[LiveProp]
    public ?string $rebookError = null;

    #[LiveProp(writable: true)]
    public string $noteContent = '';

    #[LiveProp]
    public ?string $noteError = null;

    #[LiveProp]
    public ?string $flash = null;

    #[LiveProp]
    public bool $flashIsError = false;

    public function __construct(
        private OrderRepository $orderRepository,
        private OrderNoteRepository $noteRepository,
        private OrderCancellationService $cancellationService,
        private OrderNoteRecorder $noteRecorder,
        private EventDispatcherInterface $eventDispatcher,
        private MoneyService $moneyService,
        private Security $security,
        private PaginatorInterface $paginator,
        private OrderRebookingService $rebookingService,
    ) {
    }

    /**
     * The current page of orders. A page past the end (orders cancelled away,
     * search narrowed) falls back to the last page.
     */
    public function getOrdersPagination(): Pagination
    {
        $pagination = $this->getPagination();
        if ($pagination->isOutOfRange() && ($pagination->getTotalPages() ?? 0) >= 1) {
            $this->page = (int) $pagination->getTotalPages();
            $pagination = $this->getPagination();
        }

        return $pagination;
    }

    protected function createPagination(): PaginationBuilder
    {
        $search = mb_substr(trim($this->search), 0, self::MAX_SEARCH_LENGTH);
        $query = new OrderListQuery(search: $search === '' ? null : $search);

        return $this->paginator
            ->query($this->orderRepository->createListQueryBuilder($query))
            ->perPage(self::PER_PAGE);
    }

    public function getNotesOrder(): ?Order
    {
        return $this->notesFor === null ? null : $this->orderRepository->findOneByOrderNumber($this->notesFor);
    }

    /**
     * @return list<OrderNote>
     */
    public function getNotes(): array
    {
        $order = $this->getNotesOrder();
        if ($order === null) {
            return [];
        }

        return array_values(iterator_to_array(
            $this->noteRepository->findByOrderPaginated($order, new PageQuery(page: 1, limit: 100))->getIterator(),
            false,
        ));
    }

    public function getRebookOrder(): ?Order
    {
        return $this->rebookFor === null ? null : $this->orderRepository->findOneByOrderNumber($this->rebookFor);
    }

    public function getRebookSource(): ?Event
    {
        $order = $this->getRebookOrder();

        return $order === null ? null : $this->rebookingService->sourceEvent($order);
    }

    /**
     * @return Event[]
     */
    public function getRebookTargets(): array
    {
        $order = $this->getRebookOrder();

        return $order === null ? [] : $this->rebookingService->targets($order);
    }

    public function getRebookTarget(): ?Event
    {
        foreach ($this->getRebookTargets() as $event) {
            if ($event->id->toRfc4122() === $this->rebookEvent) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @return list<array{ticketType: \App\Entity\TicketType, name: string, quantity: int}>
     */
    public function getRebookTypes(): array
    {
        $order = $this->getRebookOrder();

        return $order === null ? [] : $this->rebookingService->sourceTypes($order);
    }

    public function getCancelOrder(): ?Order
    {
        return $this->cancelFor === null ? null : $this->orderRepository->findOneByOrderNumber($this->cancelFor);
    }

    public function canCancel(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::PENDING, OrderStatus::PROCESSING, OrderStatus::COMPLETED], true);
    }

    public function canRebook(Order $order): bool
    {
        return $order->status === OrderStatus::COMPLETED;
    }

    public function canResendMail(Order $order): bool
    {
        return $order->status->triggersFulfilment();
    }

    /** "12,00" for the table; the euro sign is added in the template. */
    public function formatMoney(Order $order): string
    {
        return number_format($this->moneyService->toFloat($order->totalGross), 2, ',', '.');
    }

    #[LiveAction]
    public function openNotes(#[LiveArg] string $orderNumber): void
    {
        $this->clearFeedback();
        $this->notesFor = $this->findOrder($orderNumber)?->orderNumber;
        $this->noteContent = '';
    }

    #[LiveAction]
    public function addNote(): void
    {
        $order = $this->getNotesOrder();
        if ($order === null) {
            $this->closeModal();

            return;
        }

        $content = trim($this->noteContent);
        if ($content === '') {
            $this->noteError = 'Vul een notitie in.';

            return;
        }
        if (mb_strlen($content) > self::MAX_NOTE_LENGTH) {
            $this->noteError = sprintf('Een notitie mag maximaal %d tekens bevatten.', self::MAX_NOTE_LENGTH);

            return;
        }

        $this->noteRecorder->record($order, $content);
        $this->noteContent = '';
        $this->noteError = null;
    }

    #[LiveAction]
    public function askCancel(#[LiveArg] string $orderNumber): void
    {
        $this->clearFeedback();
        $order = $this->findOrder($orderNumber);
        $this->cancelFor = $order !== null && $this->canCancel($order) ? $order->orderNumber : null;
    }

    #[LiveAction]
    public function confirmCancel(): void
    {
        $order = $this->getCancelOrder();
        $this->cancelFor = null;
        if ($order === null) {
            return;
        }

        try {
            $cancelled = $this->cancellationService->cancel($order, $this->operatorName());
            $this->flash = $cancelled
                ? sprintf('Bestelling %s is geannuleerd.', $order->orderNumber)
                : sprintf('Bestelling %s was al geannuleerd.', $order->orderNumber);
            $this->flashIsError = false;
        } catch (\DomainException $e) {
            $this->flash = sprintf(
                'Bestelling %s kan niet worden geannuleerd: %s',
                $order->orderNumber,
                $e->getMessage(),
            );
            $this->flashIsError = true;
        }
    }

    #[LiveAction]
    public function resendMail(#[LiveArg] string $orderNumber): void
    {
        $this->clearFeedback();
        $order = $this->findOrder($orderNumber);
        if ($order === null) {
            return;
        }

        if (!$this->canResendMail($order)) {
            $this->flash = sprintf(
                'De mail van bestelling %s kan alleen worden verstuurd voor voltooide bestellingen.',
                $order->orderNumber,
            );
            $this->flashIsError = true;

            return;
        }

        // Same path as POST /api/orders/{n}/process: fulfilment is idempotent and queues the confirmation mail.
        $this->eventDispatcher->dispatch(new OrderCompletedEvent($order));
        $this->noteRecorder->record($order, sprintf(
            'Orderverwerking opnieuw gestart door %s (tickets en bevestigingsmail).',
            $this->operatorName(),
        ));

        $this->flash = sprintf('De bevestigingsmail van bestelling %s wordt opnieuw verstuurd.', $order->orderNumber);
        $this->flashIsError = false;
    }

    #[LiveAction]
    public function openRebook(#[LiveArg] string $orderNumber): void
    {
        $this->clearFeedback();
        $order = $this->findOrder($orderNumber);
        $this->resetRebook();
        if ($order === null || !$this->canRebook($order)) {
            return;
        }

        $this->rebookFor = $order->orderNumber;
        if ($this->rebookingService->sourceEvent($order) === null) {
            $this->rebookError = 'Deze bestelling hoort niet bij één show en kan niet worden omgeboekt.';
        }
    }

    /** Fills the ticket type mapping from the names when a show is picked. */
    public function autoMap(): void
    {
        $this->rebookError = null;
        $order = $this->getRebookOrder();
        $target = $this->getRebookTarget();
        $this->rebookMap = $order !== null && $target !== null
            ? $this->rebookingService->suggestMapping($order, $target)
            : [];
    }

    #[LiveAction]
    public function confirmRebook(): void
    {
        $order = $this->getRebookOrder();
        $target = $this->getRebookTarget();
        if ($order === null) {
            $this->closeModal();

            return;
        }
        if ($target === null) {
            $this->rebookError = 'Kies de show waarnaar je wilt omboeken.';

            return;
        }

        try {
            $this->rebookingService->rebook($order, $target, $this->rebookMap, $this->operatorName());
        } catch (\DomainException $e) {
            $this->rebookError = $e->getMessage();

            return;
        }

        $this->resetRebook();
        $this->flash = sprintf('Bestelling %s is omgeboekt naar %s.', $order->orderNumber, $target->name);
        $this->flashIsError = false;
    }

    #[LiveAction]
    public function closeModal(): void
    {
        $this->resetRebook();
        $this->notesFor = null;
        $this->cancelFor = null;
        $this->noteContent = '';
        $this->noteError = null;
    }

    private function resetRebook(): void
    {
        $this->rebookFor = null;
        $this->rebookEvent = '';
        $this->rebookMap = [];
        $this->rebookError = null;
    }

    private function clearFeedback(): void
    {
        $this->flash = null;
        $this->flashIsError = false;
        $this->noteError = null;
    }

    private function findOrder(string $orderNumber): ?Order
    {
        return $this->orderRepository->findOneByOrderNumber($orderNumber);
    }

    private function operatorName(): string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 'onbekend';
        }

        return $user->name !== '' ? $user->name : $user->email;
    }
}
