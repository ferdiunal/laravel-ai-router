<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Services;

use Ferdiunal\AiDevApi\Catalog\ProviderCatalog;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Illuminate\Validation\ValidationException;

final class ProviderKeyManager
{
    public function __construct(private readonly ProviderModelCacheService $modelCache) {}

    public function add(string $platform, string $apiKey, string $label, bool $refreshModels = true): AiDevApiProviderKey
    {
        ProviderCatalog::get($platform);

        $label = trim($label);
        if ($label === '') {
            throw ValidationException::withMessages(['label' => 'Provider label is required.']);
        }

        $exists = AiDevApiProviderKey::query()
            ->where('platform', $platform)
            ->where('label', $label)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['label' => "Label [{$label}] already exists for provider [{$platform}]."]);
        }

        $key = AiDevApiProviderKey::query()->create([
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

    public function remove(int $id): bool
    {
        return (bool) AiDevApiProviderKey::query()->whereKey($id)->delete();
    }

    public function setEnabled(int $id, bool $enabled): AiDevApiProviderKey
    {
        $key = AiDevApiProviderKey::query()->findOrFail($id);
        $key->forceFill(['enabled' => $enabled])->save();

        return $key->refresh();
    }
}
