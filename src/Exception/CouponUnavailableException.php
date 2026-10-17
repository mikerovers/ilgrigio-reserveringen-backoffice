<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The coupon attached to a checkout can no longer be redeemed (e.g. its usage
 * limit was reached by a concurrent order).
 */
final class CouponUnavailableException extends \RuntimeException
{
}
