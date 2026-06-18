<?php

namespace App\Service;

use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;

/**
 * Thin wrapper around moneyphp/money so all checkout money math runs in integer
 * cents. Avoids floating-point rounding drift: amounts are summed first and a
 * tax-inclusive gross is split into [net, tax] that always re-sums to the gross.
 */
final class MoneyService
{
    private DecimalMoneyFormatter $formatter;

    public function __construct()
    {
        $this->formatter = new DecimalMoneyFormatter(new ISOCurrencies());
    }

    /**
     * Convert a euro amount (float, as stored in the session/cart) to a Money
     * value object. Rounds to the nearest cent once, at the boundary.
     */
    public function eurosToMoney(float $euros): Money
    {
        return Money::EUR((int) round($euros * 100));
    }

    /**
     * Split a tax-inclusive gross amount into [net, tax] for the given VAT rate
     * (as a percentage, e.g. 9.0). The two parts always re-sum to $gross to the
     * cent, since allocation distributes the remainder rather than rounding each
     * part independently.
     *
     * @return array{0: Money, 1: Money} [net, tax]
     */
    public function splitGross(Money $gross, float $taxRatePercent): array
    {
        return $gross->allocate([100, $taxRatePercent]);
    }

    /**
     * Format a Money value as a WooCommerce-ready decimal string (period
     * decimal separator, no thousands separator), e.g. "66.06".
     */
    public function format(Money $money): string
    {
        return $this->formatter->format($money);
    }

    /**
     * Format a Money value as a float (for session values consumed by the
     * checkout template / Stimulus controller).
     */
    public function toFloat(Money $money): float
    {
        return (float) $this->format($money);
    }
}
