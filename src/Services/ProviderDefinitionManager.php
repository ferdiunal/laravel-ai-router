<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Services;

use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderDefinition;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Ferdiunal\LaravelAiRouter\Support\ProviderDefinitionValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages runtime custom provider definitions and their related model, fallback, cache, and key artifacts.
 */
final class ProviderDefinitionManager
{
    /**
     * Validate and persist a runtime OpenAI-compatible provider definition.
     *
     * @param  array<string, mixed>  $headers
     */
    public function addOpenAiCompatible(
        string $platform,
        string $name,
        string $baseUrl,
        array $headers = [],
        int $timeoutMs = 15_000,
        bool $requiresPlaceholderKey = false,
    ): LaravelAiRouterProviderDefinition {
        $platform = trim($platform);
        $name = trim($name);

        $errors = [];

        if (($error = ProviderDefinitionValidator::platformError($platform)) !== null) {
            $errors['platform'] = $error;
        }

        if ($name === '') {
            $errors['name'] = 'Provider name is required.';
        }

        if (($error = ProviderDefinitionValidator::baseUrlError($baseUrl, requirePublicDns: true)) !== null) {
            $errors['base_url'] = $error;
        }

        if (($error = ProviderDefinitionValidator::headersError($headers)) !== null) {
            $errors['headers'] = $error;
        }

        if (array_key_exists($platform, ProviderCatalog::builtIn())) {
            $errors['platform'] = "Provider platform [{$platform}] is reserved by the built-in catalog.";
        }

        if (! array_key_exists($platform, ProviderCatalog::builtIn()) && array_key_exists($platform, ProviderCatalog::all())) {
            $errors['platform'] = "Provider platform [{$platform}] already exists in the active provider catalog.";
        }

        if (LaravelAiRouterProviderDefinition::query()->where('platform', $platform)->exists()) {
            $errors['platform'] = "Provider platform [{$platform}] already exists.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $definition = ProviderDefinitionValidator::normalizeOpenAiCompatible($platform, [
            'name' => $name,
            'base_url' => $baseUrl,
            'headers' => $headers,
            'timeout_ms' => $timeoutMs,
            'requires_placeholder_key' => $requiresPlaceholderKey,
        ]);

        if ($definition === null) {
            throw ValidationException::withMessages(['base_url' => 'Provider definition is invalid.']);
        }

        return LaravelAiRouterProviderDefinition::query()->create([
            'platform' => $platform,
            'name' => $definition['name'],
            'adapter' => 'openai-compatible',
            'base_url' => $definition['base_url'],
            'headers' => $definition['headers'],
            'timeout_ms' => $definition['timeout_ms'],
            'requires_placeholder_key' => $definition['requires_placeholder_key'],
            'enabled' => true,
        ]);
    }

    /**
     * Remove a runtime custom provider definition and deactivate related runtime artifacts.
     */
    public function remove(int $id): bool
    {
        return DB::connection(config('laravel-ai-router.database.connection') ?: 'laravel-ai-router')->transaction(function () use ($id): bool {
            $definition = LaravelAiRouterProviderDefinition::query()->find($id);
            if (! $definition instanceof LaravelAiRouterProviderDefinition) {
                return false;
            }

            $this->deactivateRuntimeArtifacts($definition->platform);

            return (bool) $definition->delete();
        });
    }

    /**
     * Enable or disable a runtime custom provider definition by its package database primary key.
     */
    public function setEnabled(int $id, bool $enabled): LaravelAiRouterProviderDefinition
    {
        return DB::connection(config('laravel-ai-router.database.connection') ?: 'laravel-ai-router')->transaction(function () use ($id, $enabled): LaravelAiRouterProviderDefinition {
            $definition = LaravelAiRouterProviderDefinition::query()->findOrFail($id);
            $definition->forceFill(['enabled' => $enabled])->save();

            if (! $enabled) {
                $this->deactivateRuntimeArtifacts($definition->platform);
            }

            return $definition->refresh();
        });
    }

    /**
     * Disable runtime model, fallback, cache, and key artifacts associated with a custom provider definition.
     */
    private function deactivateRuntimeArtifacts(string $platform): void
    {
        $modelIds = LaravelAiRouterModel::query()
            ->where('platform', $platform)
            ->pluck('id')
            ->all();

        if ($modelIds !== []) {
            LaravelAiRouterFallback::query()
                ->whereIn('laravel_ai_router_model_id', $modelIds)
                ->update(['enabled' => false]);
        }

        LaravelAiRouterProviderKey::query()
            ->where('platform', $platform)
            ->update(['enabled' => false]);

        LaravelAiRouterProviderModelCache::query()
            ->where('platform', $platform)
            ->update(['enabled' => false]);

        LaravelAiRouterModel::query()
            ->where('platform', $platform)
            ->update(['enabled' => false]);
    }
}
