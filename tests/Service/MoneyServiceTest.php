<?php

namespace App\Tests\Service;

use App\Service\MoneyService;
use Money\Money;
use PHPUnit\Framework\TestCase;

class MoneyServiceTest extends TestCase
{
    private MoneyService $moneyService;

    protected function setUp(): void
    {
        $this->moneyService = new MoneyService();
    }

    public function testEurosToMoneyRoundsToNearestCent(): void
    {
        $this->assertSame("1995", $this->moneyService->eurosToMoney(19.95)->getAmount());
        $this->assertSame("1000", $this->moneyService->eurosToMoney(10.00)->getAmount());
    }

    public function testSplitGrossReconcilesToTheCent(): void
    {
        $gross = Money::EUR(1200); // €12.00 incl. 9% BTW

        [$net, $tax] = $this->moneyService->splitGross($gross, 9.0);

        $this->assertTrue(
            $net->add($tax)->equals($gross),
            "net + tax must re-sum to the gross",
        );
    }

    public function testReportedBugCaseHasNoDrift(): void
    {
        // 1x €10.00 + 6x €12.00 = €82.00 tax-inclusive (the reported case).
        $lines = [
            $this->moneyService->eurosToMoney(10.00)->multiply(1),
            $this->moneyService->eurosToMoney(12.00)->multiply(6),
        ];

        $grossTotal = Money::EUR(0);
        $netTotal = Money::EUR(0);
        $taxTotal = Money::EUR(0);

        foreach ($lines as $grossLine) {
            [$net, $tax] = $this->moneyService->splitGross($grossLine, 9.0);
            $grossTotal = $grossTotal->add($grossLine);
            $netTotal = $netTotal->add($net);
            $taxTotal = $taxTotal->add($tax);
        }

        // Order total must equal the €82.00 the customer agreed to — not €82.01.
        $this->assertSame("8200", $grossTotal->getAmount());
        $this->assertSame("82.00", $this->moneyService->format($grossTotal));

        // Net + tax must reconcile exactly to the gross.
        $this->assertTrue($netTotal->add($taxTotal)->equals($grossTotal));
    }

    public function testFormatProducesWooCommerceDecimalString(): void
    {
        $this->assertSame("66.06", $this->moneyService->format(Money::EUR(6606)));
        $this->assertSame("0.83", $this->moneyService->format(Money::EUR(83)));
    }
}
