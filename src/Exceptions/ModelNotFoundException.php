<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Exceptions;

use RuntimeException;

final class ModelNotFoundException extends RuntimeException
{
    public static function forModel(string $modelId): self
    {
        return new self("Model '{$modelId}' is not in the enabled AI Dev API catalog.");
    }
}
