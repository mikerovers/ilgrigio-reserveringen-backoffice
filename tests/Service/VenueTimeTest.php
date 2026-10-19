<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Controller\Admin\VenueDateTimeField;
use App\Service\VenueTime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

class VenueTimeTest extends KernelTestCase
{
    public function testSummerLocalTimeIsTwoHoursAheadOfUtc(): void
    {
        $utc = VenueTime::fromLocal('2027-07-01 14:00');

        self::assertSame('UTC', $utc->getTimezone()->getName());
        self::assertSame('2027-07-01 12:00:00', $utc->format('Y-m-d H:i:s'));
        self::assertSame('14:00', VenueTime::toLocal($utc)->format('H:i'));
    }

    public function testWinterLocalTimeIsOneHourAheadOfUtc(): void
    {
        self::assertSame('2027-01-15 13:00:00', VenueTime::fromLocal('2027-01-15 14:00')->format('Y-m-d H:i:s'));
    }

    public function testKernelRunsInUtcAndTwigShowsAmsterdamTime(): void
    {
        self::bootKernel();

        self::assertSame('UTC', date_default_timezone_get());

        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');
        $rendered = $twig->createTemplate("{{ at|date('Y-m-d H:i') }}")->render([
            'at' => new \DateTimeImmutable('2027-07-01 12:00:00', new \DateTimeZone('UTC')),
        ]);

        self::assertSame('2027-07-01 14:00', $rendered);
    }

    public function testAdminFieldEditsInAmsterdamTimeAndStoresUtc(): void
    {
        $dto = VenueDateTimeField::new('startsAt')->getAsDto();

        self::assertSame('Europe/Amsterdam', $dto->getCustomOption('timezone'));
        self::assertSame('UTC', $dto->getFormTypeOption('model_timezone'));
        self::assertSame('Europe/Amsterdam', $dto->getFormTypeOption('view_timezone'));
    }
}
