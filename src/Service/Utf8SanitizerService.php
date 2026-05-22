<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class Utf8SanitizerService
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Recursively sanitize all string values in an array to ensure valid UTF-8
     */
    public function sanitizeArray(array $data, string $path = ""): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $currentPath = $path ? $path . "." . $key : (string) $key;

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value, $currentPath);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitizeString($value, $currentPath);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize a string to ensure valid UTF-8 encoding
     */
    public function sanitizeString(string $value, string $path = ""): string
    {
        // Check if string is already valid UTF-8
        if (mb_check_encoding($value, "UTF-8")) {
            return $value;
        }

        // Try to detect the actual encoding and convert to UTF-8
        $detectedEncoding = mb_detect_encoding(
            $value,
            ["UTF-8", "ISO-8859-1", "ISO-8859-15", "Windows-1252", "ASCII"],
            true,
        );

        if ($detectedEncoding && $detectedEncoding !== "UTF-8") {
            $converted = mb_convert_encoding(
                $value,
                "UTF-8",
                $detectedEncoding,
            );

            $this->logger->warning(
                "Converted string from detected encoding to UTF-8",
                [
                    "path" => $path,
                    "from_encoding" => $detectedEncoding,
                    "original_length" => strlen($value),
                    "converted_length" => strlen($converted),
                    "original_preview" => substr($value, 0, 50),
                    "converted_preview" => substr($converted, 0, 50),
                ],
            );

            return $converted;
        }

        // If detection failed, use aggressive sanitization
        // This replaces invalid UTF-8 sequences with the replacement character
        $sanitized = mb_convert_encoding($value, "UTF-8", "UTF-8");

        $this->logger->warning(
            "Sanitized string with invalid UTF-8 characters",
            [
                "path" => $path,
                "original_length" => strlen($value),
                "sanitized_length" => strlen($sanitized),
                "original_preview" => substr($value, 0, 50),
                "sanitized_preview" => substr($sanitized, 0, 50),
            ],
        );

        return $sanitized;
    }

    /**
     * Validate that data is JSON-encodable with proper UTF-8
     */
    public function validateJsonEncodable(array $data): bool
    {
        try {
            json_encode($data, JSON_THROW_ON_ERROR);
            return true;
        } catch (\JsonException $e) {
            $this->logger->error("Data is not JSON-encodable", [
                "error" => $e->getMessage(),
                "code" => $e->getCode(),
            ]);
            return false;
        }
    }

    /**
     * Get detailed information about why data is not JSON-encodable
     */
    public function getJsonEncodingErrors(array $data, string $path = ""): array
    {
        $errors = [];

        foreach ($data as $key => $value) {
            $currentPath = $path ? $path . "." . $key : (string) $key;

            if (is_array($value)) {
                $errors = array_merge(
                    $errors,
                    $this->getJsonEncodingErrors($value, $currentPath),
                );
            } elseif (is_string($value)) {
                if (!mb_check_encoding($value, "UTF-8")) {
                    $errors[] = [
                        "path" => $currentPath,
                        "type" => "invalid_utf8",
                        "value_preview" => substr($value, 0, 100),
                    ];
                }
            }
        }

        return $errors;
    }
}
