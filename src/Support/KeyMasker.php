<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Support;

final class KeyMasker
{
    public static function mask(?string $key): string
    {
        if (! is_string($key) || strlen($key) < 8) {
            return '****';
        }

        return substr($key, 0, 4).'...'.substr($key, -4);
    }
}
