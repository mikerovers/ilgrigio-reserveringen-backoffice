<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class WooCommerceService
{
    public function __construct(
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        private Utf8SanitizerService $utf8Sanitizer,
        private string $baseUrl,
        private string $consumerKey,
        private string $consumerSecret
    ) {
    }

    /**
     * Process WooCommerce webhook data
     */
    public function processWebhookData(array $data): array
    {
        $this->logger->info('Processing WooCommerce webhook data', [
            'order_id' => $data['id'] ?? 'unknown'
        ]);

        // Add any specific WooCommerce data processing logic here
        return $data;
    }

    /**
     * Create a new order in WooCommerce
     */
    public function createOrder(array $orderData): array
    {
        try {
            $this->logger->info('Creating WooCommerce order', [
                'customer_email' => $orderData['billing']['email'] ?? 'unknown'
            ]);

            $response = $this->httpClient->request('POST', $this->baseUrl . '/wp-json/wc/v3/orders', [
                'json' => $orderData,
                'query' => [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                ],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray();

            // Sanitize UTF-8 encoding in response
            $content = $this->utf8Sanitizer->sanitizeArray($content);

            if ($statusCode === 201 && isset($content['id'])) {
                $this->logger->info('Order created successfully in WooCommerce', [
                    'order_id' => $content['id'],
                    'status' => $content['status'] ?? 'unknown'
                ]);

                return [
                    'success' => true,
                    'order' => $content
                ];
            }

            $this->logger->error('Failed to create order in WooCommerce', [
                'status_code' => $statusCode,
                'response' => $content
            ]);

            return [
                'success' => false,
                'message' => $content['message'] ?? 'Failed to create order'
            ];
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('HTTP error during order creation', [
                'error' => $e->getMessage(),
                'status_code' => $e->getResponse()->getStatusCode()
            ]);

            return [
                'success' => false,
                'message' => 'Error communicating with WooCommerce'
            ];
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during order creation', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Unexpected error occurred'
            ];
        }
    }

    /**
     * Get checkout payment URL for an order
     */
    public function getCheckoutUrl(int $orderId, string $returnUrl): ?array
    {
        try {
            $this->logger->info('Getting checkout URL for order', [
                'order_id' => $orderId,
                'return_url' => $returnUrl
            ]);

            $response = $this->httpClient->request('POST', $this->baseUrl . '/wp-json/wc/v3/get-checkout-url', [
                'query' => [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                    'order_id' => $orderId,
                    'return_url' => $returnUrl,
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray();

            // Sanitize UTF-8 encoding in response
            $content = $this->utf8Sanitizer->sanitizeArray($content);

            if ($statusCode === 200 && isset($content['checkout_url']) && isset($content['order_id'])) {
                $this->logger->info('Checkout URL retrieved successfully', [
                    'order_id' => $content['order_id'],
                    'checkout_url' => $content['checkout_url']
                ]);

                return [
                    'checkout_url' => $content['checkout_url'],
                    'order_id' => $content['order_id']
                ];
            }

            $this->logger->error('Failed to get checkout URL', [
                'order_id' => $orderId,
                'status_code' => $statusCode,
                'response' => $content
            ]);

            return null;
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('HTTP error while getting checkout URL', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error while getting checkout URL', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Get order details from WooCommerce
     */
    public function getOrder(int $orderId): ?array
    {
        try {
            $this->logger->info('Getting order details from WooCommerce', [
                'order_id' => $orderId
            ]);

            $response = $this->httpClient->request('GET', $this->baseUrl . "/wp-json/wc/v3/orders/{$orderId}", [
                'query' => [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray();

            // Sanitize UTF-8 encoding in response
            $content = $this->utf8Sanitizer->sanitizeArray($content);

            if ($statusCode === 200 && isset($content['id'])) {
                $this->logger->info('Order details retrieved successfully', [
                    'order_id' => $content['id'],
                    'status' => $content['status'] ?? 'unknown'
                ]);

                return $content;
            }

            $this->logger->error('Failed to get order details from WooCommerce', [
                'order_id' => $orderId,
                'status_code' => $statusCode,
                'response' => $content
            ]);

            return null;
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('HTTP error while getting order details', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error while getting order details', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Fetch an order, distinguishing a definitive "not an order" (404) from a transient
     * failure (5xx / network / timeout).
     *
     * Returns the order array on success, or null when WooCommerce responds 404 — i.e. the
     * id is not a WooCommerce order (e.g. a Tickera ticket post id re-firing the
     * woocommerce_order_status_completed hook). Throws WooCommerceTransientException for
     * failures that may succeed on retry, so callers can retry rather than discard.
     *
     * @throws WooCommerceTransientException
     */
    public function fetchOrderOrThrowOnTransient(int $orderId): ?array
    {
        try {
            $this->logger->info('Getting order details from WooCommerce', [
                'order_id' => $orderId
            ]);

            $response = $this->httpClient->request('GET', $this->baseUrl . "/wp-json/wc/v3/orders/{$orderId}", [
                'query' => [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $this->utf8Sanitizer->sanitizeArray($response->toArray());

            if ($statusCode === 200 && isset($content['id'])) {
                $this->logger->info('Order details retrieved successfully', [
                    'order_id' => $content['id'],
                    'status' => $content['status'] ?? 'unknown'
                ]);

                return $content;
            }

            $this->logger->error('Failed to get order details from WooCommerce', [
                'order_id' => $orderId,
                'status_code' => $statusCode,
                'response' => $content
            ]);

            throw new WooCommerceTransientException(
                "Unexpected status {$statusCode} fetching order {$orderId}"
            );
        } catch (HttpExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            // 404 is definitive: this id is not a WooCommerce order, do not retry.
            if ($statusCode === 404) {
                $this->logger->info('Order not found in WooCommerce (not an order id)', [
                    'order_id' => $orderId,
                ]);

                return null;
            }

            $this->logger->error('HTTP error while getting order details', [
                'order_id' => $orderId,
                'status_code' => $statusCode,
                'error' => $e->getMessage()
            ]);

            throw new WooCommerceTransientException($e->getMessage(), 0, $e);
        } catch (WooCommerceTransientException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error while getting order details', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            throw new WooCommerceTransientException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Update order status in WooCommerce
     */
    public function updateOrderStatus(int $orderId, string $status): bool
    {
        try {
            $this->logger->info('Updating order status in WooCommerce', [
                'order_id' => $orderId,
                'new_status' => $status
            ]);

            $response = $this->httpClient->request('PUT', $this->baseUrl . "/wp-json/wc/v3/orders/{$orderId}", [
                'json' => [
                    'status' => $status
                ],
                'query' => [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray();

            // Sanitize UTF-8 encoding in response
            $content = $this->utf8Sanitizer->sanitizeArray($content);

            if ($statusCode === 200 && isset($content['id'])) {
                $this->logger->info('Order status updated successfully', [
                    'order_id' => $content['id'],
                    'status' => $content['status'] ?? 'unknown'
                ]);

                return true;
            }

            $this->logger->error('Failed to update order status in WooCommerce', [
                'order_id' => $orderId,
                'status_code' => $statusCode,
                'response' => $content
            ]);

            return false;
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('HTTP error while updating order status', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return false;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error while updating order status', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Extract Mollie payment ID from order meta data
     */
    public function getMolliePaymentId(array $orderData): ?string
    {
        // Look for Mollie payment ID in various possible meta fields
        $metaData = $orderData['meta_data'] ?? [];

        foreach ($metaData as $meta) {
            if (isset($meta['key']) && isset($meta['value'])) {
                // Common Mollie meta keys
                if (in_array($meta['key'], ['_mollie_payment_id', 'mollie_payment_id', '_payment_id'])) {
                    return $meta['value'];
                }
            }
        }

        // Fallback: check transaction_id field
        if (isset($orderData['transaction_id']) && !empty($orderData['transaction_id'])) {
            return $orderData['transaction_id'];
        }

        return null;
    }

    /**
     * Validate WooCommerce order data structure
     */
    public function validateOrderData(array $orderData): bool
    {
        $requiredFields = ['id', 'billing'];

        foreach ($requiredFields as $field) {
            if (!isset($orderData[$field])) {
                $this->logger->warning('Missing required field in order data', [
                    'field' => $field,
                    'order_id' => $orderData['id'] ?? 'unknown'
                ]);
                return false;
            }
        }

        return true;
    }
}
