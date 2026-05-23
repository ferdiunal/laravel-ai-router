<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Exceptions;

use RuntimeException;

/**
 * Represents provider credential failures that should invalidate keys and avoid curated model fallback.
 */
final class ProviderAuthenticationException extends RuntimeException
{
    /**
     * Create an authentication failure that preserves provider name and HTTP status for routing decisions.
     */
    public function __construct(
        private readonly string $providerName,
        private readonly int $statusCode,
        string $message,
    ) {
        parent::__construct("{$providerName} API error {$statusCode}: {$message}", $statusCode);
    }

    /**
     * Return the displayable upstream provider name that produced the authentication failure.
     */
    public function providerName(): string
    {
        return $this->providerName;
    }

    /**
     * Return the upstream HTTP status code associated with the authentication failure.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
