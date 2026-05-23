<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $platform
 * @property string $model_id
 * @property int|null $provider_key_id
 * @property string $window_type
 * @property Carbon|null $window_starts_at
 * @property Carbon|null $window_ends_at
 * @property int $request_count
 * @property int $token_count
 * @property Carbon|null $cooldown_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AiDevApiRateWindow extends AiDevApiBaseModel
{
    protected $table = 'ai_dev_api_rate_windows';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider_key_id' => 'int',
            'window_starts_at' => 'datetime',
            'window_ends_at' => 'datetime',
            'request_count' => 'int',
            'token_count' => 'int',
            'cooldown_until' => 'datetime',
        ];
    }
}
