<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Coupon;
use App\Enum\DiscountType;
use App\Repository\CouponRepository;
use App\Service\CouponCodeGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CouponCodeGeneratorTest extends TestCase
{
    private CouponRepository&MockObject $couponRepository;
    private CouponCodeGenerator $generator;

    protected function setUp(): void
    {
        $this->couponRepository = $this->createMock(CouponRepository::class);
        $this->generator = new CouponCodeGenerator($this->couponRepository);
    }

    public function testGeneratesRequestedNumberOfCodes(): void
    {
        $this->couponRepository->method('findOneByCode')->willReturn(null);

        $codes = $this->generator->generate('ilgrigio', 10, 5);

        $this->assertCount(5, $codes);
    }

    public function testGeneratedCodesMatchExpectedPattern(): void
    {
        $this->couponRepository->method('findOneByCode')->willReturn(null);

        $codes = $this->generator->generate('ilgrigio', 10, 3);

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^ilgrigio-10-[a-z2-9]{6}$/', $code);
        }
    }

    public function testGeneratedCodesUseOnlySafeAlphabet(): void
    {
        $this->couponRepository->method('findOneByCode')->willReturn(null);

        $codes = $this->generator->generate('ilgrigio', 10, 20);

        foreach ($codes as $code) {
            $suffix = substr($code, strrpos($code, '-') + 1);
            $this->assertMatchesRegularExpression('/^[a-z2-9]+$/', $suffix);
            $this->assertDoesNotMatchRegularExpression('/[01loi]/', $suffix);
        }
    }

    public function testGeneratedCodesAreUniqueWithinBatch(): void
    {
        $this->couponRepository->method('findOneByCode')->willReturn(null);

        $codes = $this->generator->generate('ilgrigio', 10, 50);

        $this->assertCount(50, array_unique($codes));
    }

    public function testRetriesWhenGeneratedCodeAlreadyExistsInDatabase(): void
    {
        $existing = new Coupon('ilgrigio-10-aaaaaa', DiscountType::PERCENT, 1000);

        $callCount = 0;
        $this->couponRepository
            ->method('findOneByCode')
            ->willReturnCallback(function () use (&$callCount, $existing) {
                ++$callCount;

                return $callCount === 1 ? $existing : null;
            });

        $codes = $this->generator->generate('ilgrigio', 10, 1);

        $this->assertCount(1, $codes);
        $this->assertGreaterThanOrEqual(2, $callCount);
    }

    public function testAvoidsCollisionWithExistingBatchCodes(): void
    {
        $this->couponRepository->method('findOneByCode')->willReturn(null);

        $codes = $this->generator->generate('ilgrigio', 10, 5, ['ilgrigio-10-abcdef']);

        $this->assertNotContains('ilgrigio-10-abcdef', $codes);
    }
}
