<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('async')]
class SendOrderEmailMessage
{
    public function __construct(
        private array $orderData,
        private string $pdfDownloadToken
    ) {
        // Validate that orderData is JSON-encodable to prevent serialization errors
        // This ensures the message can be properly stored in SQS for async processing
        try {
            json_encode($this->orderData, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Provide detailed error information to help debug UTF-8 issues
            $errors = $this->findEncodingErrors($this->orderData);
            $errorDetails = empty($errors) ? 'unknown' : implode(', ', array_slice($errors, 0, 5));

            throw new \InvalidArgumentException(
                sprintf(
                    'Order data contains values that cannot be JSON-encoded (likely malformed UTF-8). ' .
                    'Order ID: %s. Error: %s. Problematic fields: %s',
                    $this->orderData['id'] ?? 'unknown',
                    $e->getMessage(),
                    $errorDetails
                ),
                0,
                $e
            );
        }
    }

    public function getOrderData(): array
    {
        return $this->orderData;
    }

    public function getPdfDownloadToken(): string
    {
        return $this->pdfDownloadToken;
    }

    /**
     * Find fields with encoding issues for better error messages
     */
    private function findEncodingErrors(array $data, string $path = ''): array
    {
        $errors = [];

        foreach ($data as $key => $value) {
            $currentPath = $path ? $path . '.' . $key : (string) $key;

            if (is_array($value)) {
                $errors = array_merge($errors, $this->findEncodingErrors($value, $currentPath));
            } elseif (is_string($value)) {
                if (!mb_check_encoding($value, 'UTF-8')) {
                    $errors[] = sprintf(
                        '%s (preview: %s)',
                        $currentPath,
                        substr($value, 0, 30)
                    );
                }
            }
        }

        return $errors;
    }
}
