<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Instants are stored and exchanged in UTC regardless of the server's php.ini;
        // convert with App\Service\VenueTime for Amsterdam wall-clock display.
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
