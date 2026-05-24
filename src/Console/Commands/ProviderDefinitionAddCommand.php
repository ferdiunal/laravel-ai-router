<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Commands;

use Ferdiunal\LaravelAiRouter\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\LaravelAiRouter\Services\ProviderDefinitionManager;
use Ferdiunal\LaravelAiRouter\Support\ProviderDefinitionValidator;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\warning;

/**
 * Creates a runtime OpenAI-compatible provider definition after validating URL, header, and timeout constraints.
 */
final class ProviderDefinitionAddCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'laravel-ai-router:provider-definition:add';

    protected $description = 'Add a custom OpenAI-compatible provider definition using Laravel Prompts.';

    /**
     * Collect and persist a validated runtime OpenAI-compatible provider definition through prompts.
     */
    public function handle(ProviderDefinitionManager $definitions): int
    {
        $platform = $this->textPrompt('Provider slug', 'custom-openai', required: true);
        $name = $this->textPrompt('Provider name', 'Custom OpenAI Proxy', required: true);
        $baseUrl = $this->textPrompt('OpenAI-compatible base URL', 'https://example.com/custom/v1', required: true);
        $headersJson = $this->textPrompt('Extra headers JSON', '{}');
        $timeoutMs = (int) $this->textPrompt('Timeout in milliseconds', '15000', required: true);
        $requiresPlaceholderKey = $this->confirmPrompt('Can this provider use an anonymous placeholder key?', false);
        $modelsEndpointEnabled = $this->confirmPrompt('Use live /models endpoint discovery?', true);
        $declaredModelsText = $this->textPrompt('Declared model IDs or JSON metadata list (optional)', '');
        $declaredModels = $this->parseDeclaredModels($declaredModelsText);
        if ($declaredModels === null) {
            warning('Declared models must be comma-separated model IDs or a JSON list of model IDs/model metadata objects.');

            return self::FAILURE;
        }

        $validationMethodDefault = $modelsEndpointEnabled ? 'models' : 'chat';
        $validationMethod = strtolower(trim($this->textPrompt('Credential validation method (models or chat)', $validationMethodDefault, required: true)));
        if (ProviderDefinitionValidator::normalizeValidationMethod($validationMethod) === null) {
            warning('Validation method must be either models or chat.');

            return self::FAILURE;
        }

        $validationModelDefault = (string) ((ProviderDefinitionValidator::normalizeDeclaredModels($declaredModels) ?? [])[0]['model_id'] ?? '');
        $validationModel = $validationMethod === 'chat'
            ? $this->textPrompt('Chat validation model', $validationModelDefault, required: $validationModelDefault === '')
            : $validationModelDefault;

        $headers = json_decode($headersJson !== '' ? $headersJson : '{}', true);
        if (! is_array($headers)) {
            warning('Invalid headers JSON. Use an object like {"X-Title":"Laravel AI Router"}.');

            return self::FAILURE;
        }

        try {
            $definition = $definitions->addOpenAiCompatible(
                platform: $platform,
                name: $name,
                baseUrl: $baseUrl,
                headers: $headers,
                timeoutMs: $timeoutMs,
                requiresPlaceholderKey: $requiresPlaceholderKey,
                modelsEndpointEnabled: $modelsEndpointEnabled,
                validationMethod: $validationMethod,
                validationModel: $validationModel === '' ? null : $validationModel,
                declaredModels: $declaredModels,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                warning($field.': '.implode(' ', $messages));
            }

            return self::FAILURE;
        }

        info("Added {$definition->platform} ({$definition->name}).");
        outro('Custom provider definition saved. Add a provider key next with laravel-ai-router:provider:add.');

        return self::SUCCESS;
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
}
