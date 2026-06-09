<?php

namespace App\Service;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NewRelicMonologHandler extends AbstractProcessingHandler
{
    private const NEW_RELIC_API_ENDPOINT = "/log/v1";

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $licenseKey,
        private string $endpoint,
        private string $appName,
        private string $environment,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $payload = $this->formatPayload($record);

            $response = $this->httpClient->request("POST", $this->buildUrl(), [
                "headers" => [
                    "Content-Type" => "application/json",
                    "Api-Key" => $this->licenseKey,
                ],
                "json" => $payload,
                "timeout" => 5,
                "buffer" => false,
            ]);
            $response->cancel();
        } catch (\Exception $e) {
            // Silently fail to prevent infinite logging loops
            // Do NOT use error_log, trigger_error, or any logging here
        }
    }

    private function formatPayload(LogRecord $record): array
    {
        $logEntry = [
            "timestamp" => $record->datetime->getTimestamp() * 1000, // New Relic expects milliseconds
            "message" => $this->interpolate($record->message, $record->context),
            "level" => $record->level->getName(),
            "level_name" => $record->level->getName(),
            "channel" => $record->channel,
            "app.name" => $this->appName,
            "environment" => $this->environment,
            "hostname" => gethostname() ?: "unknown",
        ];

        // Add context data
        if (!empty($record->context)) {
            foreach ($record->context as $key => $value) {
                $logEntry["context." . $key] = $this->sanitizeValue($value);
            }
        }

        // Add extra data
        if (!empty($record->extra)) {
            foreach ($record->extra as $key => $value) {
                $logEntry["extra." . $key] = $this->sanitizeValue($value);
            }
        }

        return [
            [
                "logs" => [$logEntry],
            ],
        ];
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        if (is_object($value)) {
            if (method_exists($value, "__toString")) {
                return (string) $value;
            }
            return get_class($value);
        }

        return "unsupported_type";
    }

    private function buildUrl(): string
    {
        return rtrim($this->endpoint, "/") . self::NEW_RELIC_API_ENDPOINT;
    }

    /**
     * Interpolates PSR-3 style placeholders in the message.
     * Replaces {key} with the value from context array.
     *
     * @param string $message Message with {placeholders}
     * @param array<string, mixed> $context Context values
     * @return string Interpolated message
     */
    private function interpolate(string $message, array $context): string
    {
        if (strpos($message, "{") === false) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_null($value) || is_scalar($value) || (is_object($value) && method_exists($value, "__toString"))) {
                $replacements["{" . $key . "}"] = $value;
            }
        }

        return strtr($message, $replacements);
    }
}
