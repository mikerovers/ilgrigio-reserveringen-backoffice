<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Coupon;
use App\Enum\DiscountType;
use App\Repository\CouponRepository;
use App\Service\CouponResolver;
use App\Service\DiscountCalculator;
use App\Service\MoneyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class CouponResolverTest extends TestCase
{
    private MockObject&CouponRepository $repository;
    private CouponResolver $resolver;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CouponRepository::class);
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-10-17 12:00'));

        $this->resolver = new CouponResolver(
            $this->repository,
            new DiscountCalculator(new MoneyService()),
            $clock,
        );
    }

    public function testBlankAndNullCodesResolveToNull(): void
    {
        $this->repository->expects($this->never())->method('findOneByCode');

        $this->assertNull($this->resolver->resolve(null));
        $this->assertNull($this->resolver->resolve('   '));
    }

    public function testCodeIsTrimmedAndValidCouponResolved(): void
    {
        $coupon = new Coupon('ABC', DiscountType::PERCENT, 1000);
        $this->repository->expects($this->once())->method('findOneByCode')->with('ABC')->willReturn($coupon);
        $this->repository->method('isExhausted')->willReturn(false);

        $this->assertSame($coupon, $this->resolver->resolve(' ABC '));
    }

    public function testInactiveExpiredAndExhaustedCouponsAreRejected(): void
    {
        $inactive = new Coupon('A', DiscountType::PERCENT, 1000);
        $inactive->active = false;
        $expired = new Coupon('B', DiscountType::PERCENT, 1000);
        $expired->expiresAt = new \DateTimeImmutable('2026-10-01');
        $exhausted = new Coupon('C', DiscountType::PERCENT, 1000);
        $exhausted->usageLimit = 1;

        $this->repository->method('findOneByCode')->willReturnMap([
            ['A', $inactive], ['B', $expired], ['C', $exhausted], ['D', null],
        ]);
        $this->repository->method('isExhausted')->willReturnCallback(
            static fn (Coupon $c): bool => $c === $exhausted,
        );

        foreach (['A', 'B', 'C', 'D'] as $code) {
            $this->assertNull($this->resolver->resolve($code), $code);
        }
    }
}
