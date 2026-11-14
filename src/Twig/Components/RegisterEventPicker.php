<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\DTO\CreateOrderRequest;
use App\DTO\Discount;
use App\DTO\RegisterOrderCustomerRequest;
use App\DTO\RegisterOrderDiscountRequest;
use App\DTO\RegisterOrderItemRequest;
use App\Entity\Event;
use App\Entity\EventCategory;
use App\Entity\TicketType;
use App\Enum\DiscountType;
use App\Enum\RegisterDiscountType;
use App\Exception\RegisterOrderException;
use App\Repository\EventCategoryRepository;
use App\Repository\EventRepository;
use App\Service\DiscountCalculator;
use App\Service\RegisterOrderService;
use Money\Money;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Left half of the kassa screen: pick a show series (event category), see all of
 * its upcoming shows and select one. The right half (the sale itself) follows later.
 *
 * Live components are reachable on every host under /_components, so the class
 * carries its own IsGranted: the register firewall alone does not protect it.
 */
#[AsLiveComponent(name: 'RegisterEventPicker', template: 'components/register/event_picker.html.twig')]
#[IsGranted('ROLE_REGISTER')]
final class RegisterEventPicker
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    private const MAX_SEARCH_LENGTH = 64;

    /** Id of the chosen category; empty means the first one. */
    #[LiveProp(writable: true, onUpdated: 'clearSelection')]
    public string $category = '';

    #[LiveProp(writable: true)]
    public string $search = '';

    /** Id of the selected show. */
    #[LiveProp]
    public ?string $selected = null;

    public const DISCOUNT_GROUP = 'group';
    public const DISCOUNT_PERCENT = 'percent';
    public const DISCOUNT_FIXED = 'fixed';

    /** Order number of the sale that was just saved (shown as a confirmation). */
    #[LiveProp]
    public ?string $savedOrder = null;

    #[LiveProp(writable: true)]
    public string $firstName = '';

    #[LiveProp(writable: true)]
    public string $lastName = '';

    #[LiveProp(writable: true)]
    public string $city = '';

    #[LiveProp(writable: true)]
    public string $note = '';

    /** @var array<string, int|string> ticket type id => quantity */
    #[LiveProp(writable: true)]
    public array $quantities = [];

    /** One of the DISCOUNT_* kinds, null when no discount is added. */
    #[LiveProp]
    public ?string $discountKind = null;

    /** Typed value of a percentage (10 or 12,5) or fixed (5,00 euro) discount. */
    #[LiveProp(writable: true)]
    public string $discountValue = '';

    #[LiveProp]
    public ?string $saleError = null;

    /** Makes a double click on "Opslaan" create only one order. */
    #[LiveProp]
    public string $formKey = '';

    public function __construct(
        private EventCategoryRepository $categoryRepository,
        private EventRepository $eventRepository,
        private ClockInterface $clock,
        private RegisterOrderService $orderService,
        private DiscountCalculator $discountCalculator,
        private UrlGeneratorInterface $urlGenerator,
        private float $groupDiscountPercent = 10.0,
    ) {
    }

    /**
     * @return EventCategory[]
     */
    public function getCategories(): array
    {
        return $this->categoryRepository->findAllOrdered();
    }

    public function getCurrentCategory(): ?EventCategory
    {
        $categories = $this->getCategories();
        foreach ($categories as $category) {
            if ($category->id->toRfc4122() === $this->category) {
                return $category;
            }
        }

        return $categories[0] ?? null;
    }

    /**
     * @return Event[]
     */
    public function getEvents(): array
    {
        $category = $this->getCurrentCategory();
        if ($category === null) {
            return [];
        }

        $search = mb_substr(trim($this->search), 0, self::MAX_SEARCH_LENGTH);

        return $this->eventRepository->findForRegister(
            $category->id,
            $search === '' ? null : $search,
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );
    }

    /** The selected show, only when it is still part of the visible list. */
    public function getSelectedEvent(): ?Event
    {
        if ($this->selected === null) {
            return null;
        }
        foreach ($this->getEvents() as $event) {
            if ($event->id->toRfc4122() === $this->selected) {
                return $event;
            }
        }

        return null;
    }

    public function clearSelection(): void
    {
        $this->selected = null;
        $this->resetSale();
        $this->savedOrder = null;
    }

    #[LiveAction]
    public function select(#[LiveArg] string $id): void
    {
        $id = Uuid::isValid($id) ? $id : null;
        if ($id !== $this->selected) {
            $this->resetSale();
            $this->savedOrder = null;
        }
        $this->selected = $id;
        $this->seedQuantities();
    }

    /** The configured group discount, e.g. "10". */
    public function getGroupDiscountLabel(): string
    {
        return $this->formatPercent($this->groupDiscountPercent);
    }

    /**
     * Ticket types the register can sell for the selected show.
     *
     * @return TicketType[]
     */
    public function getTicketTypes(): array
    {
        $event = $this->getSelectedEvent();
        if ($event === null) {
            return [];
        }

        return array_values(array_filter(
            $event->ticketTypes->toArray(),
            static fn (TicketType $t): bool => !$t->disabled,
        ));
    }

    /**
     * Cleaned quantities per ticket type id: only the shown types, whole numbers >= 0.
     *
     * @return array<string, int>
     */
    public function getCart(): array
    {
        $cart = [];
        foreach ($this->getTicketTypes() as $type) {
            $id = $type->id->toRfc4122();
            $raw = $this->quantities[$id] ?? 0;
            $qty = is_numeric($raw) ? (int) $raw : 0;
            if ($qty > 0) {
                $cart[$id] = min($qty, 999);
            }
        }

        return $cart;
    }

    public function getTotalQuantity(): int
    {
        return array_sum($this->getCart());
    }

    public function getSubtotal(): Money
    {
        $cart = $this->getCart();
        $subtotal = Money::EUR(0);
        foreach ($this->getTicketTypes() as $type) {
            $qty = $cart[$type->id->toRfc4122()] ?? 0;
            $subtotal = $subtotal->add($type->price->multiply($qty));
        }

        return $subtotal;
    }

    /** The discount as the pricing code understands it, or null while it is empty or invalid. */
    public function getDiscount(): ?Discount
    {
        return match ($this->discountKind) {
            self::DISCOUNT_GROUP => new Discount(DiscountType::PERCENT, (int) round($this->groupDiscountPercent * 100)),
            self::DISCOUNT_PERCENT => $this->parsePercent() === null
                ? null
                : new Discount(DiscountType::PERCENT, (int) round($this->parsePercent() * 100)),
            self::DISCOUNT_FIXED => $this->parseCents() === null
                ? null
                : new Discount(DiscountType::FIXED_CART, $this->parseCents()),
            default => null,
        };
    }

    public function getDiscountAmount(): Money
    {
        $discount = $this->getDiscount();
        if ($discount === null) {
            return Money::EUR(0);
        }
        $subtotal = $this->getSubtotal();
        $amount = $this->discountCalculator->discountGross($discount, $subtotal);

        return $amount->greaterThan($subtotal) ? $subtotal : $amount;
    }

    public function getTotal(): Money
    {
        return $this->getSubtotal()->subtract($this->getDiscountAmount());
    }

    public function discountLabel(): string
    {
        return match ($this->discountKind) {
            self::DISCOUNT_GROUP => 'Groepskorting (' . $this->getGroupDiscountLabel() . '%)',
            self::DISCOUNT_PERCENT => 'Percentage',
            self::DISCOUNT_FIXED => 'Vast bedrag',
            default => '',
        };
    }

    /** "12,00" for display; the euro sign is added in the template. */
    public function money(Money $money): string
    {
        return number_format(((int) $money->getAmount()) / 100, 2, ',', '.');
    }

    /** The − / + buttons of a ticket row. */
    #[LiveAction]
    public function adjust(#[LiveArg] string $id, #[LiveArg] int $delta): void
    {
        foreach ($this->getTicketTypes() as $type) {
            if ($type->id->toRfc4122() === $id) {
                $current = $this->getCart()[$id] ?? 0;
                $this->quantities[$id] = max(0, min(999, $current + max(-1, min(1, $delta))));

                return;
            }
        }
    }

    #[LiveAction]
    public function addDiscount(#[LiveArg] string $kind): void
    {
        if (!in_array($kind, [self::DISCOUNT_GROUP, self::DISCOUNT_PERCENT, self::DISCOUNT_FIXED], true)) {
            return;
        }
        $this->discountKind = $kind;
        $this->discountValue = '';
        $this->saleError = null;
    }

    #[LiveAction]
    public function removeDiscount(): void
    {
        $this->discountKind = null;
        $this->discountValue = '';
    }

    #[LiveAction]
    public function save(): void
    {
        $this->saleError = null;
        $this->savedOrder = null;

        $event = $this->getSelectedEvent();
        if ($event === null) {
            $this->saleError = 'Selecteer eerst een show.';

            return;
        }

        $first = trim($this->firstName);
        $last = trim($this->lastName);
        $city = trim($this->city);
        if ($first === '' || $last === '' || $city === '') {
            $this->saleError = 'Vul voornaam, achternaam en woonplaats in.';

            return;
        }

        $cart = $this->getCart();
        if ($cart === []) {
            $this->saleError = 'Kies minstens één kaartje.';

            return;
        }

        $discount = null;
        if ($this->discountKind !== null) {
            if ($this->getDiscount() === null) {
                $this->saleError = $this->discountKind === self::DISCOUNT_PERCENT
                    ? 'Vul een geldig percentage in (1 tot 100, maximaal twee decimalen).'
                    : 'Vul een geldig bedrag in.';

                return;
            }
            $discount = $this->discountKind === self::DISCOUNT_FIXED
                ? new RegisterOrderDiscountRequest(RegisterDiscountType::FIXED, (int) $this->parseCents(), 'Kassa: vast bedrag')
                : new RegisterOrderDiscountRequest(
                    RegisterDiscountType::PERCENT,
                    $this->discountKind === self::DISCOUNT_GROUP ? $this->groupDiscountPercent : (float) $this->parsePercent(),
                    $this->discountKind === self::DISCOUNT_GROUP ? 'Groepskorting' : 'Kassa: percentage',
                );
        }

        if ($this->formKey === '') {
            $this->formKey = 'register-ui-' . Uuid::v4()->toRfc4122();
        }

        $items = [];
        foreach ($cart as $id => $qty) {
            $items[] = new RegisterOrderItemRequest($id, $qty);
        }
        $request = new CreateOrderRequest(
            items: $items,
            customer: new RegisterOrderCustomerRequest(firstName: $first, lastName: $last, city: $city),
            discount: $discount,
            note: trim($this->note) === '' ? null : mb_substr(trim($this->note), 0, 1000),
        );

        try {
            $result = $this->orderService->create($request, $this->formKey);
        } catch (RegisterOrderException | \RuntimeException $e) {
            $this->saleError = $e->getMessage();

            return;
        }

        $this->savedOrder = $result->order->orderNumber;
        $this->resetSale();

        // The browser opens the print sheet with the order summary and every QR code.
        $this->dispatchBrowserEvent('register-tickets:open', [
            'url' => $this->urlGenerator->generate('register_order_tickets', ['orderNumber' => $this->savedOrder]),
        ]);
    }

    private function resetSale(): void
    {
        $this->firstName = '';
        $this->lastName = '';
        $this->city = '';
        $this->note = '';
        $this->quantities = [];
        $this->discountKind = null;
        $this->discountValue = '';
        $this->saleError = null;
        $this->formKey = '';
        $this->seedQuantities();
    }

    /**
     * Gives every ticket type of the selected show a quantity of 0. The browser can only
     * write to `quantities.<id>` when that key already exists in the component data.
     */
    private function seedQuantities(): void
    {
        $this->quantities = [];
        foreach ($this->getTicketTypes() as $type) {
            $this->quantities[$type->id->toRfc4122()] = 0;
        }
    }

    /** Percentage typed as "10" or "12,5": 0 < p <= 100, at most two decimals. */
    private function parsePercent(): ?float
    {
        $value = str_replace(',', '.', trim($this->discountValue));
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value)) {
            return null;
        }
        $percent = (float) $value;

        return $percent > 0 && $percent <= 100 ? $percent : null;
    }

    /** Euro amount typed as "5" or "5,50" as whole cents (> 0). */
    private function parseCents(): ?int
    {
        $value = str_replace(',', '.', trim($this->discountValue));
        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $value)) {
            return null;
        }
        $cents = (int) round((float) $value * 100);

        return $cents > 0 ? $cents : null;
    }

    private function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',');
    }

    /** Re-renders the list (the footer refresh button). */
    #[LiveAction]
    public function refresh(): void
    {
    }
}
