<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Services;

use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
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
    public function __construct(
        private readonly ProviderModelCacheService $modelCache,
        private readonly ProviderModelSelectionManager $modelSelection,
    ) {}

    /**
     * Store an encrypted provider key and optionally refresh its provider-label-scoped model cache.
     *
     * @param  array<string, mixed>  $credentialMetadata
     */
    public function add(string $platform, string $apiKey, string $label, bool $refreshModels = true, array $credentialMetadata = []): LaravelAiRouterProviderKey
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

        [$apiKey, $credentialMetadata] = $this->normalizeCredentialMetadata($platform, $apiKey, $credentialMetadata);

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
            'credential_metadata' => $credentialMetadata === [] ? null : $credentialMetadata,
            'status' => 'unknown',
            'enabled' => true,
        ]);

        if ($refreshModels) {
            $rows = $this->modelCache->refreshForKey($key);

            if ($rows !== []) {
                $this->modelSelection->setSelectedModelIdsForKey(
                    $key,
                    array_values(array_unique(array_map(
                        fn (LaravelAiRouterProviderModelCache $row): string => (string) $row->model_id,
                        $rows,
                    ))),
                );
            }
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

    /**
     * Normalize provider-specific credential metadata without keeping Cloudflare account IDs inside encrypted token values.
     *
     * @param  array<string, mixed>  $credentialMetadata
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function normalizeCredentialMetadata(string $platform, string $apiKey, array $credentialMetadata): array
    {
        if ($platform !== 'cloudflare') {
            return [$apiKey, $this->filledStringMetadata($credentialMetadata)];
        }

        $accountId = trim((string) ($credentialMetadata['account_id'] ?? $credentialMetadata['accountId'] ?? ''));

        if ($accountId === '' && str_contains($apiKey, ':')) {
            [$accountId, $apiKey] = explode(':', $apiKey, 2);
            $accountId = trim($accountId);
            $apiKey = trim($apiKey);
        }

        if ($accountId === '') {
            throw ValidationException::withMessages(['account_id' => 'Cloudflare Workers AI account ID is required.']);
        }

        if ($apiKey === '') {
            throw ValidationException::withMessages(['api_key' => 'Cloudflare Workers AI API token is required.']);
        }

        return [$apiKey, ['account_id' => $accountId]];
    }

    /**
     * Keep only non-empty scalar metadata values so provider key rows do not store prompt noise.
     *
     * @param  array<string, mixed>  $credentialMetadata
     * @return array<string, mixed>
     */
    private function filledStringMetadata(array $credentialMetadata): array
    {
        $metadata = [];

        foreach ($credentialMetadata as $key => $value) {
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $metadata[$key] = $value;
                }
            }
        }

        return $metadata;
    }
}
