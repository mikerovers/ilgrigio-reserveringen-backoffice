<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;
use Money\Currency;
use Money\Money;

/**
 * Persists a Money\Money value object as an integer number of cents (EUR).
 *
 * The shop is single-currency (EUR), so only the amount is stored; the currency
 * is reattached on hydration. This keeps every monetary column a plain INTEGER
 * while giving safe Money arithmetic in PHP.
 */
final class MoneyType extends Type
{
    public const NAME = 'money';

    private const CURRENCY = 'EUR';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Money
    {
        if ($value === null) {
            return null;
        }

        return new Money((string) $value, new Currency(self::CURRENCY));
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if ($value === null) {
            return null;
        }

        if (!$value instanceof Money) {
            throw InvalidType::new($value, self::NAME, ['null', Money::class]);
        }

        return (int) $value->getAmount();
    }
}
