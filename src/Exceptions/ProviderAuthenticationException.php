<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Exceptions;

use RuntimeException;

final class ProviderAuthenticationException extends RuntimeException
{
    public function __construct(
        private readonly string $providerName,
        private readonly int $statusCode,
        string $message,
    ) {
        parent::__construct("{$providerName} API error {$statusCode}: {$message}", $statusCode);
    }

    public function providerName(): string
    {
        return $this->providerName;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
