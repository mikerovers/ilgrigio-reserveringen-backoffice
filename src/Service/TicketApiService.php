<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class TicketApiService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $ticketApiUrl,
        private string $ticketApiKey
    ) {
    }

    /**
     * Retrieve ticket information from the Il Grigio API
     */
    public function getTicketInformation(string $orderId): ?array
    {
        try {
            $this->logger->info('Fetching ticket information from API', [
                'order_id' => $orderId,
                'api_url' => $this->ticketApiUrl
            ]);

            $response = $this->httpClient->request('POST', $this->ticketApiUrl, [
                'json' => [
                    'api_key' => $this->ticketApiKey,
                    'order_id' => $orderId
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 30
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $this->logger->error('Ticket API returned non-200 status code', [
                    'status_code' => $statusCode,
                    'order_id' => $orderId
                ]);
                return null;
            }

            $content = $response->getContent();
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Failed to decode JSON response from ticket API', [
                    'json_error' => json_last_error_msg(),
                    'order_id' => $orderId,
                    'response_content' => substr($content, 0, 500)
                ]);
                return null;
            }

            $this->logger->info('Successfully retrieved ticket information', [
                'order_id' => $orderId,
                'event_name' => $data['event_name'] ?? 'unknown',
                'ticket_count' => count($data['tickets'] ?? [])
            ]);

            return $data;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Transport error when calling ticket API', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            return null;
        } catch (ClientExceptionInterface | ServerExceptionInterface | RedirectionExceptionInterface $e) {
            $this->logger->error('HTTP error when calling ticket API', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'status_code' => $e->getResponse()->getStatusCode()
            ]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error when calling ticket API', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Validate that the ticket API response has the expected structure
     */
    public function validateTicketResponse(array $data): bool
    {
        if (!isset($data['event_name']) || !isset($data['tickets'])) {
            $this->logger->warning('Ticket API response missing required fields', [
                'response_data' => $data
            ]);
            return false;
        }

        if (!isset($data['event_date'])) {
            $this->logger->info('Ticket API response missing optional event_date field', [
                'event_name' => $data['event_name']
            ]);
        }

        if (!is_array($data['tickets'])) {
            $this->logger->warning('Ticket API response tickets field is not an array', [
                'tickets_type' => gettype($data['tickets'])
            ]);
            return false;
        }

        foreach ($data['tickets'] as $ticketId => $ticket) {
            if (!isset($ticket['ticket_code']) || !isset($ticket['ticket_name'])) {
                $this->logger->warning('Ticket missing required fields', [
                    'ticket_id' => $ticketId,
                    'ticket_data' => $ticket
                ]);
                return false;
            }
        }

        return true;
    }
}
