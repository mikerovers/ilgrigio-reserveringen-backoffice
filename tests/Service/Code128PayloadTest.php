<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Code128Set;
use App\Exception\InvalidBarcodePayloadException;
use App\Service\Code128Payload;
use PHPUnit\Framework\TestCase;

final class Code128PayloadTest extends TestCase
{
    public function testEscapeAndUnescapeRoundTripControlBytesAndBackslashes(): void
    {
        $payload = "AAA\rBBB\\CCC\x7F";

        $escaped = Code128Payload::escape($payload);

        self::assertSame('AAA\\x0DBBB\\\\CCC\\x7F', $escaped);
        self::assertSame($payload, Code128Payload::unescape($escaped));
    }

    public function testPrintableTextIsStoredAsTyped(): void
    {
        self::assertSame('~CR setup', Code128Payload::unescape('~CR setup'));
        self::assertSame('~CR setup', Code128Payload::escape('~CR setup'));
    }

    public function testUnescapeRejectsALoneBackslash(): void
    {
        $this->expectException(InvalidBarcodePayloadException::class);

        Code128Payload::unescape('ABC\\');
    }

    public function testUnescapeRejectsAnInvalidHexEscape(): void
    {
        $this->expectException(InvalidBarcodePayloadException::class);

        Code128Payload::unescape('ABC\\xZZ');
    }

    public function testEmptyPayloadIsRejected(): void
    {
        $this->expectException(InvalidBarcodePayloadException::class);

        Code128Payload::assertEncodable('', Code128Set::AUTO);
    }

    public function testPayloadLongerThan80CharactersIsRejected(): void
    {
        $this->expectException(InvalidBarcodePayloadException::class);

        Code128Payload::assertEncodable(str_repeat('A', 81), Code128Set::AUTO);
    }

    public function testNulByteIsRejected(): void
    {
        $this->expectException(InvalidBarcodePayloadException::class);

        Code128Payload::assertEncodable("A\x00B", Code128Set::AUTO);
    }

    public function testSetCRequiresAnEvenNumberOfDigits(): void
    {
        Code128Payload::assertEncodable('1234', Code128Set::C);

        $this->expectException(InvalidBarcodePayloadException::class);
        Code128Payload::assertEncodable('123', Code128Set::C);
    }

    public function testSetCRejectsNonDigits(): void
    {
        $this->expectException(InvalidBarcodePayloadException::class);

        Code128Payload::assertEncodable('12A4', Code128Set::C);
    }

    public function testSetBRejectsControlBytes(): void
    {
        $this->expectException(InvalidBarcodePayloadException::class);

        Code128Payload::assertEncodable("\tEnter", Code128Set::B);
    }

    public function testSetARejectsLowercaseLetters(): void
    {
        $this->expectException(InvalidBarcodePayloadException::class);

        Code128Payload::assertEncodable('abc', Code128Set::A);
    }

    public function testAutoAcceptsControlBytesButRejectsNonAscii(): void
    {
        Code128Payload::assertEncodable("A\rB", Code128Set::AUTO);

        $this->expectException(InvalidBarcodePayloadException::class);
        Code128Payload::assertEncodable("A\xC3\xA9B", Code128Set::AUTO);
    }
}
