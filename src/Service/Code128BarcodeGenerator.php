<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Code128Set;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Types\TypeCode128A;
use Picqer\Barcode\Types\TypeCode128B;
use Picqer\Barcode\Types\TypeCode128C;

/**
 * Renders a scanner programming payload as an inline Code 128 SVG.
 *
 * The payload is validated before encoding, so a stored row with a bad value
 * raises InvalidBarcodePayloadException instead of rendering a broken symbol.
 */
final class Code128BarcodeGenerator
{
    private const MODULE_WIDTH = 3;
    private const HEIGHT = 100.0;

    public function render(string $payload, Code128Set $set): string
    {
        Code128Payload::assertEncodable($payload, $set);

        $type = match ($set) {
            Code128Set::AUTO => new TypeCode128(),
            Code128Set::A => new TypeCode128A(),
            Code128Set::B => new TypeCode128B(),
            Code128Set::C => new TypeCode128C(),
        };

        $barcode = $type->getBarcode(Code128Payload::toEncoding($payload));

        $renderer = (new SvgRenderer())
            ->setSvgType(SvgRenderer::TYPE_SVG_INLINE)
            ->setForegroundColor([0, 0, 0])
            ->setBackgroundColor([255, 255, 255]);

        return $renderer->render(
            $barcode,
            $barcode->getWidth() * self::MODULE_WIDTH,
            self::HEIGHT,
        );
    }
}
