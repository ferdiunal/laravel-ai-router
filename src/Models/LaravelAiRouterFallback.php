<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Models;

use Illuminate\Support\Carbon;

/**
 * Represents fallback ordering and penalty state for a routable package model.
 *
 * @property int $id
 * @property int $laravel_ai_router_model_id
 * @property int $priority
 * @property bool $enabled
 * @property int $penalty
 * @property Carbon|null $penalty_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class LaravelAiRouterFallback extends LaravelAiRouterBaseModel
{
    protected $table = 'laravel_ai_router_fallbacks';

    protected $guarded = [];

    /**
     * Return Eloquent attribute cast definitions for this model.
     */
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
