<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Services;

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persists the provider-key scoped model subset that participates in auto routing.
 */
final class ProviderModelSelectionManager
{
    /**
     * Return selected model IDs for one provider key in deterministic display order.
     *
     * @return array<int, string>
     */
    public function selectedModelIdsForKey(LaravelAiRouterProviderKey $key): array
    {
        return LaravelAiRouterProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->where('enabled', true)
            ->where('auto_enabled', true)
            ->orderBy('model_id')
            ->pluck('model_id')
            ->all();
    }

    /**
     * Replace the auto-routing selection for one provider key.
     *
     * @param  array<int, string>  $modelIds
     */
    public function setSelectedModelIdsForKey(LaravelAiRouterProviderKey $key, array $modelIds): void
    {
        $selected = $this->normalizeModelIds($modelIds);
        $available = LaravelAiRouterProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->where('enabled', true)
            ->orderBy('model_id')
            ->pluck('model_id')
            ->all();

        $unknown = array_values(array_diff($selected, $available));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'model_ids' => 'Unknown cached model IDs for provider key ['.$key->platform.' / '.$key->label.']: '.implode(', ', $unknown),
            ]);
        }

        DB::connection($key->getConnectionName())->transaction(function () use ($key, $selected): void {
            LaravelAiRouterProviderModelCache::query()
                ->where('provider_key_id', $key->getKey())
                ->update(['auto_enabled' => false]);

            if ($selected !== []) {
                LaravelAiRouterProviderModelCache::query()
                    ->where('provider_key_id', $key->getKey())
                    ->whereIn('model_id', $selected)
                    ->update(['auto_enabled' => true]);
            }
        });
    }

    /**
     * @param  array<int, string>  $modelIds
     * @return array<int, string>
     */
    private function normalizeModelIds(array $modelIds): array
    {
        return collect($modelIds)
            ->map(fn (string $modelId): string => trim($modelId))
            ->filter(fn (string $modelId): bool => $modelId !== '')
            ->unique()
            ->values()
            ->all();
    }
}
