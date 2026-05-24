<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Commands;

use Ferdiunal\LaravelAiRouter\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderDefinition;
use Ferdiunal\LaravelAiRouter\Services\ProviderDefinitionManager;
use Ferdiunal\LaravelAiRouter\Support\ProviderDefinitionValidator;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Edits declared model and model-discovery settings for runtime custom OpenAI-compatible providers.
 */
final class ProviderDefinitionModelsCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'laravel-ai-router:provider-definition:models
        {--id= : Runtime custom provider definition ID}
        {--models= : Comma-separated model IDs or a JSON list of model metadata objects}
        {--models-endpoint= : Enable or disable live /models discovery (enabled|disabled)}
        {--validation-method= : Credential validation method (models|chat)}
        {--validation-model= : Model ID used for chat validation}';

    protected $description = 'Edit declared models and /models discovery settings for a custom OpenAI-compatible provider definition.';

    /**
     * Persist declared model and validation settings for an existing runtime custom provider definition.
     */
    public function handle(ProviderDefinitionManager $definitions): int
    {
        $definition = $this->resolveDefinition();
        if (! $definition instanceof LaravelAiRouterProviderDefinition) {
            warning('No custom provider definitions found.');

            return self::SUCCESS;
        }

        $declaredModels = $this->resolveDeclaredModels($definition);
        if ($declaredModels === null) {
            warning('Declared models must be comma-separated model IDs or a JSON list of model IDs/model metadata objects.');

            return self::FAILURE;
        }

        $modelsEndpointEnabled = $this->resolveModelsEndpointEnabled($definition);
        if ($modelsEndpointEnabled === null) {
            warning('Models endpoint must be enabled or disabled.');

            return self::FAILURE;
        }

        $validationMethod = $this->resolveValidationMethod($definition, $modelsEndpointEnabled);
        if (ProviderDefinitionValidator::normalizeValidationMethod($validationMethod) === null) {
            warning('Validation method must be either models or chat.');

            return self::FAILURE;
        }

        $validationModel = $this->resolveValidationModel($definition, $declaredModels, $validationMethod);

        try {
            $updated = $definitions->updateModelSettings(
                id: (int) $definition->getKey(),
                declaredModels: $declaredModels,
                modelsEndpointEnabled: $modelsEndpointEnabled,
                validationMethod: $validationMethod,
                validationModel: $validationModel,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                warning($field.': '.implode(' ', $messages));
            }

            return self::FAILURE;
        }

        $declaredCount = count($updated->declared_models ?? []);
        $endpointLabel = $updated->models_endpoint_enabled ? 'enabled' : 'disabled';
        info("Updated {$updated->platform} ({$declaredCount} declared model(s), models endpoint {$endpointLabel}, validation {$updated->validation_method}).");

        return self::SUCCESS;
    }

    /**
     * Resolve the definition from --id or the interactive definition prompt.
     */
    private function resolveDefinition(): ?LaravelAiRouterProviderDefinition
    {
        $id = $this->option('id');
        if ($id !== null && $id !== '') {
            $definition = LaravelAiRouterProviderDefinition::query()->find((int) $id);

            return $definition instanceof LaravelAiRouterProviderDefinition ? $definition : null;
        }

        return $this->definitionPrompt('Which custom provider definition should be updated?');
    }

    /**
     * Resolve declared models from --models, an interactive prompt, or the current stored definition.
     *
     * @return array<int, mixed>|null
     */
    private function resolveDeclaredModels(LaravelAiRouterProviderDefinition $definition): ?array
    {
        $existing = $definition->declared_models ?? [];
        $models = $this->option('models');

        if ($models === null && $this->shouldPrompt()) {
            $models = $this->textPrompt(
                'Declared model IDs or JSON metadata list',
                $this->declaredModelIds($existing),
            );
        }

        if ($models === null) {
            return is_array($existing) ? $existing : [];
        }

        return $this->parseDeclaredModels((string) $models);
    }

    /**
     * Resolve live /models endpoint discovery mode from --models-endpoint, prompt, or current definition.
     */
    private function resolveModelsEndpointEnabled(LaravelAiRouterProviderDefinition $definition): ?bool
    {
        $option = $this->option('models-endpoint');
        if ($option !== null && $option !== '') {
            return $this->parseEndpointState((string) $option);
        }

        if ($this->shouldPrompt()) {
            return $this->confirmPrompt('Use live /models endpoint discovery?', (bool) $definition->models_endpoint_enabled);
        }

        return (bool) $definition->models_endpoint_enabled;
    }

    /**
     * Resolve the credential validation method from --validation-method, prompt, or current definition.
     */
    private function resolveValidationMethod(LaravelAiRouterProviderDefinition $definition, bool $modelsEndpointEnabled): string
    {
        $method = $this->option('validation-method');
        if ($method !== null && $method !== '') {
            return strtolower(trim((string) $method));
        }

        $default = (string) ($definition->validation_method ?: ($modelsEndpointEnabled ? 'models' : 'chat'));
        if (! $modelsEndpointEnabled && $default === 'models') {
            $default = 'chat';
        }

        return $this->shouldPrompt()
            ? strtolower(trim($this->textPrompt('Credential validation method (models or chat)', $default, required: true)))
            : $default;
    }

    /**
     * Resolve the chat validation model from --validation-model, prompt, current definition, or first declared model.
     *
     * @param  array<int, mixed>  $declaredModels
     */
    private function resolveValidationModel(LaravelAiRouterProviderDefinition $definition, array $declaredModels, string $validationMethod): ?string
    {
        $model = $this->option('validation-model');
        if ($model !== null) {
            $model = trim((string) $model);

            return $model === '' ? null : $model;
        }

        $default = trim((string) ($definition->validation_model ?? ''));
        if ($default === '') {
            $normalized = ProviderDefinitionValidator::normalizeDeclaredModels($declaredModels) ?? [];
            $default = (string) ($normalized[0]['model_id'] ?? '');
        }

        if ($this->shouldPrompt() && $validationMethod === 'chat') {
            $default = $this->textPrompt('Chat validation model', $default, required: true);
        }

        return $default === '' ? null : $default;
    }

    /**
     * Parse comma-separated model IDs or JSON model metadata into declared model input.
     *
     * @return array<int, mixed>|null
     */
    private function parseDeclaredModels(string $models): ?array
    {
        $models = trim($models);
        if ($models === '') {
            return [];
        }

        if (str_starts_with($models, '[')) {
            $decoded = json_decode($models, true);

            return is_array($decoded) && ProviderDefinitionValidator::normalizeDeclaredModels($decoded) !== null
                ? array_values($decoded)
                : null;
        }

        $items = array_values(array_filter(
            array_map(static fn (string $model): string => trim($model), explode(',', $models)),
            static fn (string $model): bool => $model !== '',
        ));

        return ProviderDefinitionValidator::normalizeDeclaredModels($items) === null ? null : $items;
    }

    /**
     * Parse an enabled/disabled endpoint option into a boolean.
     */
    private function parseEndpointState(string $state): ?bool
    {
        $state = strtolower(trim($state));

        return match ($state) {
            '1', 'true', 'yes', 'on', 'enabled', 'enable' => true,
            '0', 'false', 'no', 'off', 'disabled', 'disable' => false,
            default => null,
        };
    }

    /**
     * Return stored declared model IDs as a comma-separated prompt default.
     *
     * @param  array<int, mixed>  $declaredModels
     */
    private function declaredModelIds(array $declaredModels): string
    {
        $normalized = ProviderDefinitionValidator::normalizeDeclaredModels($declaredModels) ?? [];

        return collect($normalized)
            ->pluck('model_id')
            ->implode(',');
    }
}
