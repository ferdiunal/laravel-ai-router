<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Services;

use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Illuminate\Validation\ValidationException;

/**
 * Manages encrypted provider keys and triggers model-cache refreshes after key creation.
 */
final class ProviderKeyManager
{
    public const ANONYMOUS_PLACEHOLDER_KEY = 'anonymous-placeholder';

    /**
     * Initialize the manager with the service that refreshes provider-label model caches.
     */
    public function __construct(private readonly ProviderModelCacheService $modelCache) {}

    /**
     * Store an encrypted provider key and optionally refresh its provider-label-scoped model cache.
     */
    public function add(string $platform, string $apiKey, string $label, bool $refreshModels = true): LaravelAiRouterProviderKey
    {
        $definition = ProviderCatalog::get($platform);

        $label = trim($label);
        if ($label === '') {
            throw ValidationException::withMessages(['label' => 'Provider label is required.']);
        }

        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            if ((bool) ($definition['requires_placeholder_key'] ?? false)) {
                $apiKey = self::ANONYMOUS_PLACEHOLDER_KEY;
            } else {
                throw ValidationException::withMessages(['api_key' => "API key is required for provider [{$platform}]."]);
            }
        }

        $exists = LaravelAiRouterProviderKey::query()
            ->where('platform', $platform)
            ->where('label', $label)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['label' => "Label [{$label}] already exists for provider [{$platform}]."]);
        }

        $key = LaravelAiRouterProviderKey::query()->create([
            'platform' => $platform,
            'label' => $label,
            'key' => $apiKey,
            'status' => 'unknown',
            'enabled' => true,
        ]);

        if ($refreshModels) {
            $this->modelCache->refreshForKey($key);
        }

        return $key->refresh();
    }

    /**
     * Delete a provider key row by its package database primary key.
     */
    public function remove(int $id): bool
    {
        return (bool) LaravelAiRouterProviderKey::query()->whereKey($id)->delete();
    }

    /**
     * Enable or disable a provider key row by its package database primary key.
     */
    public function setEnabled(int $id, bool $enabled): LaravelAiRouterProviderKey
    {
        $key = LaravelAiRouterProviderKey::query()->findOrFail($id);
        $key->forceFill(['enabled' => $enabled])->save();

        return $key->refresh();
    }
}
