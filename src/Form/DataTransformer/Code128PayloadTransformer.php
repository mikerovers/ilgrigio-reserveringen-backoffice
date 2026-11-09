<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use App\Exception\InvalidBarcodePayloadException;
use App\Service\Code128Payload;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Round-trips a Code 128 payload between the stored bytes and the readable
 * form value: control bytes are edited as \xNN and a literal backslash as \\.
 *
 * @implements DataTransformerInterface<string|null, string|null>
 */
final class Code128PayloadTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): ?string
    {
        return $value === null ? null : Code128Payload::escape((string) $value);
    }

    public function reverseTransform(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Code128Payload::unescape((string) $value);
        } catch (InvalidBarcodePayloadException $exception) {
            throw new TransformationFailedException(
                $exception->getMessage(),
                invalidMessage: $exception->getMessage(),
            );
        }
    }
}
