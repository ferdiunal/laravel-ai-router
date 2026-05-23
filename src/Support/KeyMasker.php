<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Support;

/**
 * Masks provider API keys before they are rendered in CLI output or serialized model attributes.
 */
final class KeyMasker
{
    /**
     * Return a masked representation of a provider credential that preserves only safe identifying fragments.
     */
    public static function mask(?string $key): string
    {
        if (! is_string($key) || strlen($key) < 8) {
            return '****';
        }

        return substr($key, 0, 4).'...'.substr($key, -4);
    }
}
