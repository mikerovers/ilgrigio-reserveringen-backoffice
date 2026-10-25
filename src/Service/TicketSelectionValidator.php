<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\TicketSelectionResult;
use App\Entity\Event;
use App\Entity\TicketType;
use App\Enum\StockStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Server-side rules for a ticket selection: per-order limit, shared stock pools,
 * ticket availability and sold-out events. Used by the ticket selection Live
 * Component and by the submit handler, so both paths enforce the same rules.
 */
final class TicketSelectionValidator
{
    public function __construct(
        #[Autowire(env: 'int:MAX_TICKETS_PER_ORDER')]
        private int $maxTicketsPerOrder,
    ) {
    }

    /**
     * @param array<array-key, mixed> $quantities ticket type id => submitted quantity
     */
    public function validate(Event $event, array $quantities): TicketSelectionResult
    {
        [$rows, $rowErrors, $formErrors] = $this->parse($event, $quantities);

        if ($rows === [] && $formErrors === []) {
            $formErrors[] = 'Selecteer minimaal één ticket om door te gaan.';
        }

        $totalQuantity = array_sum(array_column($rows, 'quantity'));

        if ($totalQuantity > $this->maxTicketsPerOrder) {
            $formErrors[] = sprintf(
                'Je kunt maximaal %d tickets per bestelling kiezen. Groepen vanaf %d personen kunnen in aanmerking komen voor korting mits zij van tevoren gereserveerd hebben. Voor informatie over groepskortingen ontvangen wij graag een e-mail (info@ilgrigio.nl).',
                $this->maxTicketsPerOrder,
                $this->maxTicketsPerOrder,
            );
        }

        if ($rows !== [] && $event->stockStatus() !== StockStatus::IN_STOCK) {
            $formErrors[] = 'Deze show is uitverkocht en er kunnen geen tickets meer worden besteld.';
        }

        $stockErrors = $this->stockErrors($rows);
        foreach ($stockErrors as $ticketTypeId => $message) {
            $rowErrors[$ticketTypeId] = $message;
        }
        if ($stockErrors !== []) {
            $formErrors[] = 'Niet alle gewenste tickets zijn nog beschikbaar. Pas het aantal aan.';
        }

        return new TicketSelectionResult(
            cart: array_values($rows),
            rowErrors: $rowErrors,
            formErrors: $formErrors,
            totalQuantity: $totalQuantity,
        );
    }

    /**
     * Priced rows for the parseable, enabled part of a selection, without validation.
     * Used to show totals for the current selection while it is being edited.
     *
     * @param array<array-key, mixed> $quantities
     *
     * @return list<array{ticketType: TicketType, quantity: int}>
     */
    public function cartFor(Event $event, array $quantities): array
    {
        [$rows] = $this->parse($event, $quantities);

        return array_values($rows);
    }

    /**
     * @param array<array-key, mixed> $quantities
     *
     * @return array{0: array<string, array{ticketType: TicketType, quantity: int}>, 1: array<string, string>, 2: list<string>}
     */
    private function parse(Event $event, array $quantities): array
    {
        $ticketTypesById = [];
        foreach ($event->ticketTypes as $ticketType) {
            $ticketTypesById[(string) $ticketType->id] = $ticketType;
        }

        $rows = [];
        $rowErrors = [];
        $formErrors = [];

        foreach ($quantities as $ticketTypeId => $rawQuantity) {
            $id = (string) $ticketTypeId;
            $quantity = $this->parseQuantity($rawQuantity);

            if ($quantity === null) {
                $formErrors[] = 'Controleer de geselecteerde aantallen. Sommige waarden zijn ongeldig.';
                continue;
            }
            if ($quantity === 0) {
                continue;
            }

            $ticketType = $ticketTypesById[$id] ?? null;
            if ($ticketType === null) {
                $formErrors[] = 'Een van de geselecteerde tickets bestaat niet (meer).';
                continue;
            }
            if ($ticketType->disabled) {
                $rowErrors[$id] = 'Dit ticket kan niet online worden besteld.';
                continue;
            }

            $rows[$id] = ['ticketType' => $ticketType, 'quantity' => $quantity];
        }

        return [$rows, $rowErrors, $formErrors];
    }

    /**
     * @param array<string, array{ticketType: TicketType, quantity: int}> $rows
     *
     * @return array<string, string> ticket type id => message
     */
    private function stockErrors(array $rows): array
    {
        $demandByPool = [];
        foreach ($rows as $row) {
            $pool = $row['ticketType']->sharedStock;
            if ($pool === null || $pool->stockQuantity === null) {
                continue;
            }
            $poolId = (string) $pool->id;
            $demandByPool[$poolId] = ($demandByPool[$poolId] ?? 0) + $row['quantity'];
        }

        $errors = [];
        foreach ($rows as $id => $row) {
            $pool = $row['ticketType']->sharedStock;
            if ($pool === null || $pool->stockQuantity === null) {
                continue;
            }

            $remaining = max(0, $pool->stockQuantity);
            if ($demandByPool[(string) $pool->id] > $remaining) {
                $errors[(string) $id] = $remaining === 0
                    ? 'Dit ticket is uitverkocht.'
                    : sprintf('Er zijn nog maar %d tickets beschikbaar.', $remaining);
            }
        }

        return $errors;
    }

    private function parseQuantity(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (is_int($raw)) {
            return $raw >= 0 ? $raw : null;
        }
        if (is_string($raw) && preg_match('/^\d{1,4}$/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        return null;
    }
}
