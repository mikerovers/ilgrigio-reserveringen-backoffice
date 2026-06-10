<?php

namespace App\Service;

/**
 * Thrown when fetching a WooCommerce order fails for a reason that may succeed on retry
 * (network error, timeout, or a 5xx response) — as opposed to a definitive 404, which
 * means the id is not a WooCommerce order.
 */
class WooCommerceTransientException extends \RuntimeException
{
}
