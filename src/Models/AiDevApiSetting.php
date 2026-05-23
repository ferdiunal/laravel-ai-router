<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Models;

use Illuminate\Support\Carbon;

/**
 * @property string $key
 * @property array<string, mixed>|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AiDevApiSetting extends AiDevApiBaseModel
{
    protected $table = 'ai_dev_api_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
