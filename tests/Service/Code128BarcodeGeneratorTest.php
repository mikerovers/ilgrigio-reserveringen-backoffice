<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Code128Set;
use App\Exception\InvalidBarcodePayloadException;
use App\Service\Code128BarcodeGenerator;
use PHPUnit\Framework\TestCase;

final class Code128BarcodeGeneratorTest extends TestCase
{
    private Code128BarcodeGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new Code128BarcodeGenerator();
    }

    public function testPrintablePayloadRendersAnInlineSvgWithoutXmlDeclaration(): void
    {
        $svg = $this->generator->render('ABC123', Code128Set::AUTO);

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringNotContainsString('<?xml', $svg);
        self::assertStringNotContainsString('<!DOCTYPE', $svg);
        self::assertStringContainsString('<desc>ABC123</desc>', $svg);
        self::assertStringContainsString('<g id="bars"', $svg);
    }

    public function testControlBytesCanBeRendered(): void
    {
        $svg = $this->generator->render("ABC\r", Code128Set::AUTO);

        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('<desc>ABC' . "\r" . '</desc>', $svg);
    }

    public function testEmptyPayloadIsRejected(): void
    {
        $this->expectException(InvalidBarcodePayloadException::class);

        $this->generator->render('', Code128Set::AUTO);
    }

    public function testNonDigitsAreRejectedForSetC(): void
    {
        $this->expectException(InvalidBarcodePayloadException::class);

        $this->generator->render('AB', Code128Set::C);
    }
}
