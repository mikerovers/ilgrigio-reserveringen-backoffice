<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\VenueTime;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;

/**
 * A DateTimeField that is shown and edited in Amsterdam time while the entity
 * keeps the UTC instant.
 */
final class VenueDateTimeField
{
    public static function new(string $property): DateTimeField
    {
        return DateTimeField::new($property)
            ->setTimezone(VenueTime::TIMEZONE)
            ->setFormTypeOptions([
                'model_timezone' => VenueTime::STORAGE_TIMEZONE,
                'view_timezone' => VenueTime::TIMEZONE,
            ]);
    }
}
