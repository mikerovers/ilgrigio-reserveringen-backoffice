<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\MoneyType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Money\Money;
use PHPUnit\Framework\TestCase;

class MoneyTypeTest extends TestCase
{
    private MoneyType $type;
    private PostgreSQLPlatform $platform;

    public static function setUpBeforeClass(): void
    {
        if (!Type::hasType(MoneyType::NAME)) {
            Type::addType(MoneyType::NAME, MoneyType::class);
        }
    }

    protected function setUp(): void
    {
        $this->type = Type::getType(MoneyType::NAME);
        $this->platform = new PostgreSQLPlatform();
    }

    public function testConvertsMoneyToCents(): void
    {
        $this->assertSame(8200, $this->type->convertToDatabaseValue(Money::EUR(8200), $this->platform));
    }

    public function testConvertsCentsToMoney(): void
    {
        $money = $this->type->convertToPHPValue(8200, $this->platform);

        $this->assertInstanceOf(Money::class, $money);
        $this->assertSame('8200', $money->getAmount());
        $this->assertSame('EUR', $money->getCurrency()->getCode());
    }

    public function testNullRoundTrip(): void
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testRejectsNonMoneyValue(): void
    {
        $this->expectException(ConversionException::class);
        $this->type->convertToDatabaseValue('1234', $this->platform);
    }
}
