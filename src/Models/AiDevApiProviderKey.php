<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Models;

use Ferdiunal\AiDevApi\Support\KeyMasker;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property string $platform
 * @property string $label
 * @property string $encrypted_key
 * @property string|null $key
 * @property string $masked_key
 * @property string $status
 * @property bool $enabled
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $models_cached_at
 * @property Carbon|null $models_cache_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AiDevApiProviderKey extends AiDevApiBaseModel
{
    protected $table = 'ai_dev_api_provider_keys';

    protected $guarded = [];

    protected $hidden = [
        'encrypted_key',
    ];

    protected $appends = [
        'masked_key',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'last_checked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'models_cached_at' => 'datetime',
            'models_cache_expires_at' => 'datetime',
        ];
    }

    /** @return Attribute<string|null, string> */
    protected function key(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->encrypted_key ? Crypt::decryptString($this->encrypted_key) : null,
            set: fn (string $value): array => ['encrypted_key' => Crypt::encryptString($value)],
        );
    }

    /** @return Attribute<string, never> */
    protected function maskedKey(): Attribute
    {
        return Attribute::get(fn (): string => KeyMasker::mask($this->key));
    }

    /** @return HasMany<AiDevApiProviderModelCache, $this> */
    public function modelCaches(): HasMany
    {
        return $this->hasMany(AiDevApiProviderModelCache::class, 'provider_key_id');
    }
}
