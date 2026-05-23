<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $platform
 * @property string $name
 * @property string $adapter
 * @property string $base_url
 * @property array<string, string>|null $headers
 * @property int $timeout_ms
 * @property bool $requires_placeholder_key
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AiDevApiProviderDefinition extends AiDevApiBaseModel
{
    protected $table = 'ai_dev_api_provider_definitions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'timeout_ms' => 'int',
            'requires_placeholder_key' => 'bool',
            'enabled' => 'bool',
        ];
    }
}
