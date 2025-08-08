<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class WooCommerceProductVariationsService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $ilgrigioBaseApiUrl,
        private string $woocommerceConsumerKey,
        private string $woocommerceConsumerSecret
    ) {
    }

  /**
   * Fetch product variations for a specific product
   */
    public function getProductVariations(int $productId): array
    {
        try {
            $apiUrl = "{$this->ilgrigioBaseApiUrl}/wc/v3/products/{$productId}/variations";

            $this->logger->info('Fetching product variations from WooCommerce API', [
            'product_id' => $productId,
            'api_url' => $apiUrl
            ]);

            $response = $this->httpClient->request('GET', $apiUrl, [
            'query' => [
              'consumer_key' => $this->woocommerceConsumerKey,
              'consumer_secret' => $this->woocommerceConsumerSecret
            ],
            'timeout' => 30
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                  $this->logger->error('WooCommerce variations API returned non-200 status code', [
                    'status_code' => $statusCode,
                    'product_id' => $productId
                  ]);

                  return [];
            }

            $variations = $response->toArray();

            $this->logger->info('Successfully fetched product variations from WooCommerce API', [
            'product_id' => $productId,
            'variation_count' => count($variations)
            ]);

            return $this->transformVariations($variations);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Transport error while fetching product variations', [
            'product_id' => $productId,
            'error' => $e->getMessage()
            ]);

            return [];
        } catch (ClientExceptionInterface | ServerExceptionInterface | RedirectionExceptionInterface $e) {
            $this->logger->error('HTTP error while fetching product variations', [
            'product_id' => $productId,
            'error' => $e->getMessage()
            ]);

            return [];
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error while fetching product variations', [
            'product_id' => $productId,
            'error' => $e->getMessage()
            ]);

            return [];
        }
    }

  /**
   * Transform variations data to a more usable format
   */
    private function transformVariations(array $variations): array
    {
        $transformedVariations = [];

        foreach ($variations as $variation) {
          // Extract ticket type from attributes
            $ticketType = $this->extractTicketType($variation['attributes'] ?? []);

            $transformedVariations[] = [
            'id' => $variation['id'],
            'name' => $ticketType['name'],
            'description' => $ticketType['description'],
            'price' => $variation['price'],
            'regular_price' => $variation['regular_price'],
            'sale_price' => $variation['sale_price'],
            'stock_status' => $variation['stock_status'],
            'stock_quantity' => $variation['stock_quantity'],
            'manage_stock' => $variation['manage_stock'],
            'sku' => $variation['sku'],
            'attributes' => $variation['attributes'],
            'type' => $ticketType['type'],
            'age_range' => $ticketType['age_range'],
            'requirements' => $ticketType['requirements'],
            'image' => $variation['image']['src'] ?? null,
            ];
        }

      // Sort by price (Baby tickets first, then Minor, then Youth)
        usort($transformedVariations, function ($a, $b) {
            $priceA = floatval($a['price']);
            $priceB = floatval($b['price']);

            if ($priceA === $priceB) {
                return 0;
            }

            return $priceA < $priceB ? -1 : 1;
        });

        return $transformedVariations;
    }

  /**
   * Extract ticket type information from variation attributes
   */
    private function extractTicketType(array $attributes): array
    {
      // Default values
        $result = [
        'name' => 'Ticket',
        'description' => '',
        'type' => 'general',
        'age_range' => '',
        'requirements' => []
        ];

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute['name'] ?? '');
            $option = $attribute['option'] ?? '';

          // Map ticket types based on attribute options
            if (strpos($name, 'ticket') !== false || strpos($name, 'type') !== false) {
                switch (strtolower($option)) {
                    case 'baby ticket':
                        $result = [
                        'name' => 'Baby Ticket',
                        'description' => 'Voor kinderen onder 2 jaar oud',
                        'type' => 'baby',
                        'age_range' => '0-2 jaar',
                        'requirements' => [
                        'Ter inschrijving',
                        'Must be accompanied by adult',
                        'No advance seating'
                        ]
                        ];
                        break;
                    case 'minor ticket':
                        $result = [
                        'name' => 'Minor Ticket',
                        'description' => 'Voor kinderen van 2-11 jaar oud',
                        'type' => 'minor',
                        'age_range' => '2-11 jaar',
                        'requirements' => [
                        'Discounted price',
                        'Must be accompanied by adult',
                        'Full access to event'
                        ]
                        ];
                        break;
                    case 'youth ticket':
                          $result = [
                          'name' => 'Youth Ticket',
                          'description' => 'Voor jongeren van 12-17 jaar oud',
                          'type' => 'youth',
                          'age_range' => '12-17 jaar',
                          'requirements' => [
                        'Youth pricing',
                        'Valid ID required',
                        'Full access to event'
                          ]
                          ];
                        break;
                    default:
                          $result['name'] = $option;
                        break;
                }
            }
        }

        return $result;
    }
}
