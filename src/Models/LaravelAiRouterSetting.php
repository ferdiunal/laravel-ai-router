<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Models;

use Illuminate\Support\Carbon;

/**
 * Represents package-level settings such as the persisted default text model preference.
 *
 * @property string $key
 * @property array<string, mixed>|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class LaravelAiRouterSetting extends LaravelAiRouterBaseModel
{
    protected $table = 'laravel_ai_router_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * Return Eloquent attribute cast definitions for this model.
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
