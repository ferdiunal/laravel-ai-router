<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents a provider-label-scoped cache row for a free model discovered from a provider key.
 *
 * @property int $id
 * @property int $provider_key_id
 * @property string $platform
 * @property string $provider_label
 * @property string $model_id
 * @property string|null $display_name
 * @property int|null $context_window
 * @property int|null $rpm_limit
 * @property int|null $rpd_limit
 * @property int|null $tpm_limit
 * @property int|null $tpd_limit
 * @property string|null $budget_label
 * @property bool|null $supports_tools
 * @property bool $is_free
 * @property bool $enabled
 * @property string $source
 * @property array<string, mixed>|null $raw_metadata
 * @property Carbon|null $checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class LaravelAiRouterProviderModelCache extends LaravelAiRouterBaseModel
{
    protected $table = 'laravel_ai_router_provider_model_caches';

    protected $guarded = [];

    /**
     * Return Eloquent attribute cast definitions for this model.
     */
    protected function casts(): array
    {
        return [
            'provider_key_id' => 'int',
            'context_window' => 'int',
            'rpm_limit' => 'int',
            'rpd_limit' => 'int',
            'tpm_limit' => 'int',
            'tpd_limit' => 'int',
            'supports_tools' => 'bool',
            'is_free' => 'bool',
            'enabled' => 'bool',
            'raw_metadata' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * Define the inverse relationship from a cached model row to its owning provider key.
     *
     * @return BelongsTo<LaravelAiRouterProviderKey, $this>
     */
    public function providerKey(): BelongsTo
    {
        return $this->belongsTo(LaravelAiRouterProviderKey::class, 'provider_key_id');
    }
}
