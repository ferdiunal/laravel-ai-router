<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ai_dev_api_model_id
 * @property int $priority
 * @property bool $enabled
 * @property int $penalty
 * @property Carbon|null $penalty_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AiDevApiFallback extends AiDevApiBaseModel
{
    protected $table = 'ai_dev_api_fallbacks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'priority' => 'int',
            'penalty' => 'int',
            'penalty_updated_at' => 'datetime',
        ];
    }
}
