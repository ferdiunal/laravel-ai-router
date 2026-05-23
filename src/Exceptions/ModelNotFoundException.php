<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Exceptions;

use RuntimeException;

/**
 * Represents a routing failure caused by a requested model ID that is not present in the package catalog or cache.
 */
final class ModelNotFoundException extends RuntimeException
{
    /**
     * Create an exception for a requested model identifier that is not enabled in the package catalog.
     */
    public static function forModel(string $modelId): self
    {
        return new self("Model '{$modelId}' is not in the enabled AI Dev API catalog.");
    }
}
