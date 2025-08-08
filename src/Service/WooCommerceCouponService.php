<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class WooCommerceCouponService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $baseUrl,
        private string $consumerKey,
        private string $consumerSecret
    ) {
    }

  /**
   * Validate a coupon code through the WooCommerce API
   */
    public function validateCoupon(string $couponCode): array
    {
        try {
            $this->logger->info('Validating coupon', ['code' => $couponCode]);

            $response = $this->httpClient->request('GET', $this->baseUrl . '/wp-json/wc/v3/validate_coupon', [
            'query' => [
            'code' => $couponCode,
            'consumer_key' => $this->consumerKey,
            'consumer_secret' => $this->consumerSecret,
            ],
            'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray();

            if ($statusCode === 200 && isset($content['valid']) && $content['valid']) {
                  $this->logger->info('Coupon validation successful', [
                    'code' => $couponCode,
                    'discount_type' => $content['discount_type'] ?? 'unknown',
                    'amount' => $content['amount'] ?? 'unknown'
                  ]);

                  return [
                    'valid' => true,
                    'code' => $content['code'] ?? $couponCode,
                    'amount' => $content['amount'] ?? '0',
                    'discount_type' => $content['discount_type'] ?? 'percent',
                    'description' => $content['description'] ?? '',
                  ];
            }

            $this->logger->warning('Coupon validation failed', [
            'code' => $couponCode,
            'response' => $content
            ]);

            return [
            'valid' => false,
            'message' => $content['message'] ?? 'Invalid coupon code'
            ];
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('HTTP error during coupon validation', [
            'code' => $couponCode,
            'error' => $e->getMessage(),
            'status_code' => $e->getResponse()->getStatusCode()
            ]);

            return [
            'valid' => false,
            'message' => 'Unable to validate coupon at this time. Please try again.'
            ];
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during coupon validation', [
            'code' => $couponCode,
            'error' => $e->getMessage()
            ]);

            return [
            'valid' => false,
            'message' => 'Unable to validate coupon at this time. Please try again.'
            ];
        }
    }

  /**
   * Calculate discount amount based on coupon type and cart total
   */
    public function calculateDiscount(array $coupon, float $cartTotal): float
    {
        if (!$coupon['valid']) {
            return 0.0;
        }

        $amount = (float) $coupon['amount'];
        $discountType = $coupon['discount_type'];

        switch ($discountType) {
            case 'percent':
                return $cartTotal * ($amount / 100);
            case 'fixed_cart':
                return min($amount, $cartTotal); // Don't discount more than cart total
            default:
                $this->logger->warning('Unknown discount type', [
                'type' => $discountType,
                'amount' => $amount
                ]);
                return 0.0;
        }
    }
}
