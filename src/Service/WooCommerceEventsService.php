<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class WooCommerceEventsService
{
    private string $ilgrigioEventsApiUrl;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $ilgrigioBaseApiUrl,
        private string $woocommerceConsumerKey,
        private string $woocommerceConsumerSecret
    ) {
        $this->ilgrigioEventsApiUrl = $this->ilgrigioBaseApiUrl . '/wc/v3/events';
    }

    /**
     * Fetch available events with product information from the IlGrigio events API
     */
    public function getAvailableEvents(): array
    {
        try {
            $this->logger->info('Fetching available events with product info from IlGrigio API', [
                'api_url' => $this->ilgrigioEventsApiUrl
            ]);

            $response = $this->httpClient->request('GET', $this->ilgrigioEventsApiUrl, [
                'query' => [
                    'consumer_key' => $this->woocommerceConsumerKey,
                    'consumer_secret' => $this->woocommerceConsumerSecret
                ],
                'timeout' => 30
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $this->logger->error('IlGrigio events API returned non-200 status code', [
                    'status_code' => $statusCode
                ]);

                return [];
            }

            $events = $response->toArray();

            $this->logger->info('Successfully fetched available events with product info from IlGrigio API', [
                'event_count' => count($events)
            ]);

            return $events;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Transport error while fetching IlGrigio events', [
                'error' => $e->getMessage()
            ]);

            return [];
        } catch (ClientExceptionInterface | ServerExceptionInterface | RedirectionExceptionInterface $e) {
            $this->logger->error('HTTP error while fetching IlGrigio events', [
                'error' => $e->getMessage()
            ]);

            return [];
        } catch (\Exception $e) {
            dd($e);
            $this->logger->error('Unexpected error while fetching IlGrigio events', [
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Fetch events with product information from IlGrigio events API
     * Since the API now includes product information, no need for separate WooCommerce calls
     */
    public function getEvents(): array
    {
        try {
            // Get events with product information directly from the IlGrigio API
            $eventsWithProducts = $this->getAvailableEvents();

            if (empty($eventsWithProducts)) {
                $this->logger->warning('No events found from IlGrigio API, returning empty array');
                return [];
            }

            // Transform the events to the expected format
            $events = [];
            foreach ($eventsWithProducts as $eventData) {
                // Skip events without proper product data
                if (
                    !isset($eventData['product']) ||
                    empty($eventData['product']['stock_status'])
                ) {
                    $this->logger->debug('Skipping event with no product data', [
                        'event_id' => $eventData['id'] ?? 'unknown',
                        'event_title' => $eventData['title'] ?? 'unknown'
                    ]);
                    continue;
                }

                $events[] = $this->transformEventWithProductToEvent($eventData);
            }

            $this->logger->info('Successfully transformed events from IlGrigio API', [
                'total_events' => count($eventsWithProducts),
                'available_events_count' => count($events)
            ]);

            return $events;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Transport error while fetching IlGrigio events', [
                'error' => $e->getMessage()
            ]);
            return [];
        } catch (ClientExceptionInterface | ServerExceptionInterface | RedirectionExceptionInterface $e) {
            $this->logger->error('HTTP error while fetching IlGrigio events', [
                'error' => $e->getMessage()
            ]);
            return [];
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error while fetching IlGrigio events', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Transform event data with embedded product information to the expected event format
     */
    private function transformEventWithProductToEvent(array $eventData): array
    {
        $product = $eventData['product'];

        // Parse the date from the event API (format: "3 oktober 2025")
        $eventDate = $this->parseEventDateFromApi($eventData['date']);

        return [
            'id' => $product['id'], // Use product ID for WooCommerce linking
            'event_id' => $eventData['id'], // Store the actual event ID
            'name' => html_entity_decode($eventData['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'description' => $product['description'] ?? $product['short_description'] ?? '',
            'date' => $eventDate,
            'time' => $eventData['time'],
            'location' => $eventData['location'],
            'formatted_date' => $this->formatEventDate($eventDate),
            'image_url' => $product['image'] ?? '/images/default-event.jpg',
            'slug' => $product['slug'],
            'permalink' => $eventData['permalink'],
            'stock_quantity' => $product['stock_quantity'],
            'stock_status' => $product['stock_status'],
            'low_stock_amount' => $product['low_stock_amount'] ?? 50, // Default to 50 if not set
            'price' => $product['price'],
            'regular_price' => $product['regular_price'],
            'sale_price' => $product['sale_price'],
            'sku' => $product['sku'],
            'attributes' => $product['attributes'] ?? [],
            'variations' => $product['variations'] ?? [],
            'type' => $product['type'],
            'categories' => $product['categories'] ?? []
        ];
    }

    /**
     * Parse event date from IlGrigio API format (e.g., "3 oktober 2025")
     */
    private function parseEventDateFromApi(string $dateString): ?\DateTime
    {
        try {
            // The API returns dates in Dutch format like "3 oktober 2025"
            $monthMap = [
                'januari' => 'January',
                'februari' => 'February',
                'maart' => 'March',
                'april' => 'April',
                'mei' => 'May',
                'juni' => 'June',
                'juli' => 'July',
                'augustus' => 'August',
                'september' => 'September',
                'oktober' => 'October',
                'november' => 'November',
                'december' => 'December'
            ];

            // Convert Dutch month names to English
            $englishDateString = $dateString;
            foreach ($monthMap as $dutch => $english) {
                $englishDateString = str_ireplace($dutch, $english, $englishDateString);
            }

            return new \DateTime($englishDateString);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to parse event date from API', [
                'date_string' => $dateString,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Format event date for display in Dutch (short format for badges)
     */
    private function formatEventDate(?\DateTime $date): string
    {
        if (!$date) {
            return '';
        }

        // Short format for date badges: "3 okt"
        $monthMap = [
            1 => 'jan',
            2 => 'feb',
            3 => 'mrt',
            4 => 'apr',
            5 => 'mei',
            6 => 'jun',
            7 => 'jul',
            8 => 'aug',
            9 => 'sep',
            10 => 'okt',
            11 => 'nov',
            12 => 'dec'
        ];

        $day = $date->format('j');
        $month = $monthMap[(int)$date->format('n')];

        return $day . ' ' . $month;
    }
}
