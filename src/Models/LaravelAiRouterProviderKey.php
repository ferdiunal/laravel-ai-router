<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Models;

use Ferdiunal\LaravelAiRouter\Support\KeyMasker;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Represents an encrypted provider API key identified by provider platform and user-defined label.
 *
 * @property int $id
 * @property string $platform
 * @property string $label
 * @property string $encrypted_key
 * @property array<string, mixed>|null $credential_metadata
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
final class LaravelAiRouterProviderKey extends LaravelAiRouterBaseModel
{
    protected $table = 'laravel_ai_router_provider_keys';

    protected $guarded = [];

    protected $hidden = [
        'encrypted_key',
    ];

    protected $appends = [
        'masked_key',
    ];

    /**
     * Return Eloquent attribute cast definitions for this model.
     */
    protected function casts(): array
    {
        return [
            'credential_metadata' => 'array',
            'enabled' => 'bool',
            'last_checked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'models_cached_at' => 'datetime',
            'models_cache_expires_at' => 'datetime',
        ];
    }

    /**
     * Define the encrypted provider API-key accessor and mutator.
     *
     * @return Attribute<string|null, string>
     */
    protected function key(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->encrypted_key ? Crypt::decryptString($this->encrypted_key) : null,
            set: fn (string $value): array => ['encrypted_key' => Crypt::encryptString($value)],
        );
    }

    /**
     * Define the read-only masked provider credential accessor for safe CLI and serialization output.
     *
     * @return Attribute<string, never>
     */
    protected function maskedKey(): Attribute
    {
        return Attribute::get(fn (): string => KeyMasker::mask($this->key));
    }

    /**
     * Return the adapter-facing credential string while keeping persisted Cloudflare account IDs separate from tokens.
     */
    public function credentialForProvider(): string
    {
        $apiKey = (string) $this->key;

        if ($this->platform !== 'cloudflare' || str_contains($apiKey, ':')) {
            return $apiKey;
        }

        $accountId = trim((string) data_get($this->credential_metadata, 'account_id', ''));

        if ($accountId === '') {
            return $apiKey;
        }

        return $accountId.':'.$apiKey;
    }

    /**
     * Define the provider-key relationship to provider-label-scoped cached model rows.
     *
     * @return HasMany<LaravelAiRouterProviderModelCache, $this>
     */
    public function modelCaches(): HasMany
    {
        return $this->hasMany(LaravelAiRouterProviderModelCache::class, 'provider_key_id');
    }
}
