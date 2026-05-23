<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Services;

use Ferdiunal\AiDevApi\Catalog\ProviderCatalog;
use Ferdiunal\AiDevApi\Models\AiDevApiFallback;
use Ferdiunal\AiDevApi\Models\AiDevApiModel;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderDefinition;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderModelCache;
use Ferdiunal\AiDevApi\Support\ProviderDefinitionValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProviderDefinitionManager
{
    /** @param array<string, mixed> $headers */
    public function addOpenAiCompatible(
        string $platform,
        string $name,
        string $baseUrl,
        array $headers = [],
        int $timeoutMs = 15_000,
        bool $requiresPlaceholderKey = false,
    ): AiDevApiProviderDefinition {
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

        if (AiDevApiProviderDefinition::query()->where('platform', $platform)->exists()) {
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

        return AiDevApiProviderDefinition::query()->create([
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

    public function remove(int $id): bool
    {
        return DB::connection(config('ai-dev-api.database.connection') ?: 'ai-dev-api')->transaction(function () use ($id): bool {
            $definition = AiDevApiProviderDefinition::query()->find($id);
            if (! $definition instanceof AiDevApiProviderDefinition) {
                return false;
            }

            $this->deactivateRuntimeArtifacts($definition->platform);

            return (bool) $definition->delete();
        });
    }

    public function setEnabled(int $id, bool $enabled): AiDevApiProviderDefinition
    {
        return DB::connection(config('ai-dev-api.database.connection') ?: 'ai-dev-api')->transaction(function () use ($id, $enabled): AiDevApiProviderDefinition {
            $definition = AiDevApiProviderDefinition::query()->findOrFail($id);
            $definition->forceFill(['enabled' => $enabled])->save();

            if (! $enabled) {
                $this->deactivateRuntimeArtifacts($definition->platform);
            }

            return $definition->refresh();
        });
    }

    private function deactivateRuntimeArtifacts(string $platform): void
    {
        $modelIds = AiDevApiModel::query()
            ->where('platform', $platform)
            ->pluck('id')
            ->all();

        if ($modelIds !== []) {
            AiDevApiFallback::query()
                ->whereIn('ai_dev_api_model_id', $modelIds)
                ->update(['enabled' => false]);
        }

        AiDevApiProviderKey::query()
            ->where('platform', $platform)
            ->update(['enabled' => false]);

        AiDevApiProviderModelCache::query()
            ->where('platform', $platform)
            ->update(['enabled' => false]);

        AiDevApiModel::query()
            ->where('platform', $platform)
            ->update(['enabled' => false]);
    }
}
