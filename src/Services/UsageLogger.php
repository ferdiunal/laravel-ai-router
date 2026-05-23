<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Services;

use Ferdiunal\AiDevApi\Models\AiDevApiRequest;
use Ferdiunal\AiDevApi\Routing\RouteResult;
use Throwable;

final class UsageLogger
{
    /** @param array<string, mixed> $metadata */
    public function success(RouteResult $route, int $inputTokens, int $outputTokens, int $latencyMs, int $attempt = 1, array $metadata = []): void
    {
        $this->write([
            'platform' => $route->platform,
            'provider_label' => $route->providerLabel,
            'model_id' => $route->modelId,
            'provider_key_id' => $route->keyId,
            'status' => 'success',
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'latency_ms' => $latencyMs,
            'attempt' => $attempt,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    public function error(?RouteResult $route, Throwable|string $error, string $category, int $latencyMs, int $attempt = 1, array $metadata = []): void
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $code = $error instanceof Throwable ? (string) $error->getCode() : null;
        $routeData = $route instanceof RouteResult
            ? [
                'platform' => $route->platform,
                'provider_label' => $route->providerLabel,
                'model_id' => $route->modelId,
                'provider_key_id' => $route->keyId,
            ]
            : [
                'platform' => 'routing',
                'provider_label' => null,
                'model_id' => 'auto',
                'provider_key_id' => null,
            ];

        $this->write([
            ...$routeData,
            'status' => 'error',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'latency_ms' => $latencyMs,
            'error_type' => $error instanceof Throwable ? $error::class : null,
            'error_code' => $code,
            'error_category' => $category,
            'error_message' => $this->redact($message),
            'attempt' => $attempt,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function write(array $attributes): void
    {
        try {
            AiDevApiRequest::query()->create($attributes);
        } catch (Throwable) {
            // Usage logging must never break user prompt execution.
        }
    }

    private function redact(string $message): string
    {
        return preg_replace('/(Bearer\s+)[A-Za-z0-9._:\-]+/i', '$1[REDACTED]', $message) ?? $message;
    }
}
