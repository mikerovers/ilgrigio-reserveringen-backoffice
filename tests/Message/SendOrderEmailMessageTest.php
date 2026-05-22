<?php

namespace App\Tests\Message;

use App\Message\SendOrderEmailMessage;
use PHPUnit\Framework\TestCase;

class SendOrderEmailMessageTest extends TestCase
{
    public function testConstructorWithValidUtf8Data(): void
    {
        $orderData = [
            'id' => 12345,
            'billing' => [
                'first_name' => 'José',
                'last_name' => 'García',
                'email' => 'jose@example.com'
            ],
            'total' => '€100.00'
        ];

        $message = new SendOrderEmailMessage($orderData, 'test-token-123');

        $this->assertEquals($orderData, $message->getOrderData());
        $this->assertEquals('test-token-123', $message->getPdfDownloadToken());
    }

    public function testConstructorWithDutchCharacters(): void
    {
        $orderData = [
            'id' => 54321,
            'billing' => [
                'first_name' => 'Geërt',
                'last_name' => 'de Vries',
                'address_1' => 'Kerkstraat 123',
                'city' => 'Scheveningen'
            ],
            'notes' => 'Één kaartje voor €15,50'
        ];

        $message = new SendOrderEmailMessage($orderData, 'token-456');

        $this->assertEquals($orderData, $message->getOrderData());
    }

    public function testConstructorRejectsInvalidUtf8(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Order data contains values that cannot be JSON-encoded');
        $this->expectExceptionMessage('malformed UTF-8');

        // Create invalid UTF-8 sequence
        $invalidUtf8 = "\x80\x81\x82";

        $orderData = [
            'id' => 99999,
            'billing' => [
                'first_name' => $invalidUtf8,
                'last_name' => 'Test'
            ]
        ];

        new SendOrderEmailMessage($orderData, 'token-789');
    }

    public function testConstructorProvidesClearErrorMessage(): void
    {
        $invalidUtf8 = "\x80\x81\x82";

        $orderData = [
            'id' => 77777,
            'billing' => [
                'first_name' => $invalidUtf8,
                'email' => 'test@example.com'
            ]
        ];

        try {
            new SendOrderEmailMessage($orderData, 'token');
            $this->fail('Expected InvalidArgumentException to be thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Order ID: 77777', $e->getMessage());
            $this->assertStringContainsString('billing.first_name', $e->getMessage());
        }
    }

    public function testConstructorHandlesNestedInvalidUtf8(): void
    {
        $invalidUtf8 = "\xFF\xFE";

        $orderData = [
            'id' => 55555,
            'meta_data' => [
                [
                    'key' => '_customer_note',
                    'value' => $invalidUtf8
                ]
            ]
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('meta_data');

        new SendOrderEmailMessage($orderData, 'token-nested');
    }

    public function testConstructorAllowsComplexValidData(): void
    {
        $orderData = [
            'id' => 12345,
            'number' => 'ORD-12345',
            'status' => 'completed',
            'currency' => 'EUR',
            'total' => '150.00',
            'billing' => [
                'first_name' => 'François',
                'last_name' => 'Müller',
                'company' => 'Café Naïve B.V.',
                'address_1' => 'Straße 123',
                'city' => 'München',
                'postcode' => '80331',
                'country' => 'DE',
                'email' => 'francois@cafe-naive.de',
                'phone' => '+49 89 123456'
            ],
            'line_items' => [
                [
                    'name' => 'Ticket - Clown Show 🎭',
                    'quantity' => 2,
                    'total' => '150.00'
                ]
            ],
            'meta_data' => [
                [
                    'key' => '_customer_note',
                    'value' => 'Gelieve de tickets vóór 18:00 te versturen. Één vraag: zijn er parkeerplaatsen?'
                ]
            ]
        ];

        $message = new SendOrderEmailMessage($orderData, 'complex-token');

        $this->assertEquals($orderData, $message->getOrderData());
        $this->assertIsArray($message->getOrderData()['line_items']);
        $this->assertCount(1, $message->getOrderData()['line_items']);
    }

    public function testMessageIsSerializable(): void
    {
        $orderData = [
            'id' => 11111,
            'billing' => [
                'first_name' => 'Søren',
                'last_name' => 'Ørsted',
                'email' => 'soren@example.dk'
            ]
        ];

        $message = new SendOrderEmailMessage($orderData, 'serializable-token');

        // Test that the message can be serialized and unserialized
        $serialized = serialize($message);
        $unserialized = unserialize($serialized);

        $this->assertEquals($message->getOrderData(), $unserialized->getOrderData());
        $this->assertEquals($message->getPdfDownloadToken(), $unserialized->getPdfDownloadToken());
    }
}
