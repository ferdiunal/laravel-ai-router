<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Wizards;

use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Services\ModelPreferenceManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderKeyManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelCacheService;

use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\search;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * Coordinates the interactive provider key setup flow, including provider selection, key input, label input, model refresh, and default model selection.
 */
final class ProviderKeySetupWizard
{
    /**
     * Initialize the wizard with key, model-cache, and default-model preference services.
     */
    public function __construct(
        private readonly ProviderKeyManager $keys,
        private readonly ProviderModelCacheService $modelCache,
        private readonly ModelPreferenceManager $preferences,
    ) {}

    /**
     * Run the provider-key setup prompts, persist the key, refresh models, and store the selected default model.
     */
    public function run(bool $interactive): LaravelAiRouterProviderKey
    {
        $platform = $this->providerPrompt($interactive);
        $definition = ProviderCatalog::get($platform);
        $placeholder = ($definition['requires_placeholder_key'] ?? false) ? ProviderKeyManager::ANONYMOUS_PLACEHOLDER_KEY : '';
        $apiKey = $this->apiKeyPrompt($interactive, $placeholder);
        $label = $this->labelPrompt($interactive);

        $key = $this->keys->add($platform, $apiKey !== '' ? $apiKey : $placeholder, $label, refreshModels: true);

        info("Added {$key->platform} / {$key->label} ({$key->masked_key}).");

        $choices = $this->modelCache->choicesForKey($key);
        if (count($choices) <= 1) {
            warning('No cached available models found for this provider key yet. Defaulting to auto routing.');
        }

        $selectedModel = $this->modelPrompt($interactive, $choices);
        $this->preferences->setDefaultTextModel($selectedModel);
        info("Default text model set to {$selectedModel}.");

        outro('Provider key saved and model preference updated.');

        return $key;
    }

    /**
     * Render a searchable provider prompt restricted to routable provider definitions.
     */
    private function providerPrompt(bool $interactive): string
    {
        $options = collect(ProviderCatalog::all())
            ->filter(fn (array $definition): bool => in_array($definition['adapter'] ?? null, ['openai-compatible', 'cohere'], true))
            ->mapWithKeys(fn (array $definition, string $platform): array => [$platform => "{$definition['name']} ({$platform})"])
            ->all();

        if (! $interactive) {
            return (string) array_key_first($options);
        }

        return (string) search(
            label: 'Which provider should be added?',
            options: fn (string $value): array => collect($options)
                ->filter(fn (string $description, string $platform): bool => str_contains(strtolower($platform.' '.$description), strtolower($value)))
                ->all(),
            placeholder: 'Search provider name or platform',
            scroll: 10,
        );
    }

    /**
     * Prompt for a provider API key while keeping the raw credential out of command output.
     */
    private function apiKeyPrompt(bool $interactive, string $default): string
    {
        return $interactive ? password('API key', required: $default === '') : $default;
    }

    /**
     * Prompt for the provider-key label used to scope cache and routing rows.
     */
    private function labelPrompt(bool $interactive): string
    {
        return $interactive ? text('Label', default: 'Primary', required: true) : 'Primary';
    }

    /**
     * Render a searchable model prompt over cached model choices and return the selected model identifier.
     *
     * @param  array<string, string>  $options
     */
    private function modelPrompt(bool $interactive, array $options): string
    {
        $options = $options !== [] ? $options : ['auto' => 'Auto — route requests across healthy cached available models'];

        if (! $interactive) {
            return array_key_exists('auto', $options) ? 'auto' : (string) array_key_first($options);
        }

        return (string) search(
            label: 'Which model should be the default?',
            options: fn (string $value): array => collect($options)
                ->filter(fn (string $description, string $modelId): bool => str_contains(strtolower($modelId.' '.$description), strtolower($value)))
                ->all(),
            placeholder: 'Search model id, label, provider, or capability',
            scroll: 10,
        );
    }
}
