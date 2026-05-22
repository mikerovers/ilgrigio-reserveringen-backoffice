<?php

namespace App\Tests\Service;

use App\Service\Utf8SanitizerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class Utf8SanitizerServiceTest extends TestCase
{
    private Utf8SanitizerService $service;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new Utf8SanitizerService($this->logger);
    }

    public function testSanitizeArrayWithValidUtf8(): void
    {
        $data = [
            'name' => 'José García',
            'city' => 'Zürich',
            'email' => 'test@example.com',
            'nested' => [
                'description' => 'Café München'
            ]
        ];

        $result = $this->service->sanitizeArray($data);

        $this->assertEquals($data, $result);
    }

    public function testSanitizeArrayWithInvalidUtf8(): void
    {
        // Create a string with invalid UTF-8 (using ISO-8859-1 encoding)
        $invalidUtf8 = mb_convert_encoding('José', 'ISO-8859-1', 'UTF-8');

        $data = [
            'name' => $invalidUtf8,
            'city' => 'Amsterdam'
        ];

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Converted string from detected encoding to UTF-8'),
                $this->callback(function ($context) {
                    return isset($context['from_encoding']) &&
                           $context['from_encoding'] === 'ISO-8859-1';
                })
            );

        $result = $this->service->sanitizeArray($data);

        // Result should be valid UTF-8
        $this->assertTrue(mb_check_encoding($result['name'], 'UTF-8'));
        $this->assertEquals('Amsterdam', $result['city']);
    }

    public function testSanitizeStringWithValidUtf8(): void
    {
        $valid = 'Café Müller - €10';
        $result = $this->service->sanitizeString($valid);

        $this->assertEquals($valid, $result);
    }

    public function testSanitizeStringWithDutchCharacters(): void
    {
        $dutchText = 'Geëerd gezelschap, één vraag: hoe heet ü?';
        $result = $this->service->sanitizeString($dutchText);

        $this->assertEquals($dutchText, $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    public function testSanitizeStringDetectsIso88591(): void
    {
        // Create ISO-8859-1 encoded string
        $iso88591String = mb_convert_encoding('François', 'ISO-8859-1', 'UTF-8');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Converted string from detected encoding'),
                $this->arrayHasKey('from_encoding')
            );

        $result = $this->service->sanitizeString($iso88591String, 'test.field');

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
        $this->assertEquals('François', $result);
    }

    public function testSanitizeNestedArrays(): void
    {
        $invalidUtf8 = mb_convert_encoding('René', 'ISO-8859-1', 'UTF-8');

        $data = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'name' => $invalidUtf8
                    ]
                ]
            ]
        ];

        $this->logger->expects($this->once())
            ->method('warning');

        $result = $this->service->sanitizeArray($data);

        $this->assertTrue(mb_check_encoding($result['level1']['level2']['level3']['name'], 'UTF-8'));
        $this->assertEquals('René', $result['level1']['level2']['level3']['name']);
    }

    public function testValidateJsonEncodableWithValidData(): void
    {
        $data = [
            'name' => 'José',
            'items' => ['café', 'naïve']
        ];

        $this->assertTrue($this->service->validateJsonEncodable($data));
    }

    public function testValidateJsonEncodableWithInvalidData(): void
    {
        $invalidUtf8 = "\x80\x81\x82"; // Invalid UTF-8 sequence

        $data = [
            'name' => $invalidUtf8
        ];

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('not JSON-encodable'),
                $this->arrayHasKey('error')
            );

        $this->assertFalse($this->service->validateJsonEncodable($data));
    }

    public function testGetJsonEncodingErrorsFindsInvalidUtf8(): void
    {
        $invalidUtf8 = "\x80\x81\x82";

        $data = [
            'valid' => 'José',
            'invalid' => $invalidUtf8,
            'nested' => [
                'also_invalid' => $invalidUtf8
            ]
        ];

        $errors = $this->service->getJsonEncodingErrors($data);

        $this->assertCount(2, $errors);
        $this->assertEquals('invalid', $errors[0]['path']);
        $this->assertEquals('nested.also_invalid', $errors[1]['path']);
        $this->assertEquals('invalid_utf8', $errors[0]['type']);
    }

    public function testSanitizeArrayPreservesNonStringTypes(): void
    {
        $data = [
            'string' => 'text',
            'int' => 123,
            'float' => 45.67,
            'bool' => true,
            'null' => null,
            'array' => ['nested']
        ];

        $result = $this->service->sanitizeArray($data);

        $this->assertSame(123, $result['int']);
        $this->assertSame(45.67, $result['float']);
        $this->assertTrue($result['bool']);
        $this->assertNull($result['null']);
        $this->assertIsArray($result['array']);
    }

    public function testSanitizeWindows1252Encoding(): void
    {
        // Windows-1252 specific character (€ symbol at 0x80)
        $windows1252 = mb_convert_encoding('Price: €100', 'Windows-1252', 'UTF-8');

        $this->logger->expects($this->once())
            ->method('warning');

        $result = $this->service->sanitizeString($windows1252);

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
        $this->assertEquals('Price: €100', $result);
    }
}
