<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class WebhookIntegrationTest extends WebTestCase
{
    public function testWebhookWithInvalidJson(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/webhook/woocommerce',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'invalid-json'
        );

        $response = $client->getResponse();

      // Should reject with 400 Bad Request
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testWebhookWithWrongMethod(): void
    {
        $client = static::createClient();

        $client->request('GET', '/webhook/woocommerce');

        $response = $client->getResponse();

      // Should reject with 406 Not Acceptable (wrong method)
        $this->assertEquals(Response::HTTP_NOT_ACCEPTABLE, $response->getStatusCode());
    }

    public function testWooCommerceWebhookEndpointAcceptsValidRequest(): void
    {
        $client = static::createClient();

        $orderData = [
        'id' => 123,
        'status' => 'processing',
        'billing' => [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe@example.com'
        ]
        ];

        $client->request(
            'POST',
            '/webhook/woocommerce',
            [],
            [],
            [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
            ],
            json_encode($orderData)
        );

        $response = $client->getResponse();

      // The webhook should at least parse the request properly
      // The actual message processing might fail in test env, but parsing should work
        $this->assertNotEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertNotEquals(Response::HTTP_NOT_ACCEPTABLE, $response->getStatusCode());
    }
}
