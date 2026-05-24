<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Wizards;

use Ferdiunal\LaravelAiRouter\Adapters\ProviderAdapterRegistry;
use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Services\ProviderKeyManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelCacheService;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelSelectionManager;

use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\search;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * Coordinates the interactive provider key setup flow, including provider selection, key input, label input, model refresh, and auto-routing model selection.
 */
final class ProviderKeySetupWizard
{
    /**
     * Initialize the wizard with key, model-cache, and provider-scoped auto model selection services.
     */
    public function __construct(
        private readonly ProviderKeyManager $keys,
        private readonly ProviderModelCacheService $modelCache,
        private readonly ProviderModelSelectionManager $modelSelection,
    ) {}

    /**
     * Run the provider-key setup prompts, persist the key, refresh models, and store the provider-scoped auto model selection.
     */
    public function run(bool $interactive): LaravelAiRouterProviderKey
    {
        $platform = $this->providerPrompt($interactive);
        $definition = ProviderCatalog::get($platform);
        $placeholder = ($definition['requires_placeholder_key'] ?? false) ? ProviderKeyManager::ANONYMOUS_PLACEHOLDER_KEY : '';
        $credentialMetadata = $this->credentialMetadataPrompt($platform, $interactive);
        $apiKey = $this->apiKeyPrompt($interactive, $placeholder, $platform);
        $label = $this->labelPrompt($interactive);

        $key = $this->keys->add($platform, $apiKey !== '' ? $apiKey : $placeholder, $label, refreshModels: true, credentialMetadata: $credentialMetadata);

        info("Added {$key->platform} / {$key->label} ({$key->masked_key}).");

        $choices = $this->modelCache->choicesForKey($key);
        if (count($choices) <= 1) {
            warning('No cached available models found for this provider key yet. Auto routing model selection is empty.');
        }

        $selectedModelIds = $this->modelSelectionPrompt(
            $interactive,
            $choices,
            $this->modelSelection->selectedModelIdsForKey($key),
        );
        $this->modelSelection->setSelectedModelIdsForKey($key, $selectedModelIds);
        info('Selected '.count($selectedModelIds).' model(s) for random auto routing.');

        outro('Provider key saved and auto routing model selection updated.');

        return $key;
    }

    /**
     * Render a searchable provider prompt restricted to routable provider definitions.
     */
    private function providerPrompt(bool $interactive): string
    {
        $adapters = app(ProviderAdapterRegistry::class);

        $options = collect(ProviderCatalog::all())
            ->filter(fn (array $definition, string $platform): bool => $adapters->has($platform))
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
     * Prompt for provider-specific credential metadata that should not be packed into the API token field.
     *
     * @return array<string, string>
     */
    private function credentialMetadataPrompt(string $platform, bool $interactive): array
    {
        if ($platform !== 'cloudflare') {
            return [];
        }

        $accountId = $interactive ? text(
            label: 'Cloudflare account ID',
            placeholder: 'a1b2c3d4...',
            required: true,
        ) : '';

        $accountId = trim($accountId);

        return $accountId === '' ? [] : ['account_id' => $accountId];
    }

    /**
     * Prompt for a provider API key while keeping the raw credential out of command output.
     */
    private function apiKeyPrompt(bool $interactive, string $default, string $platform): string
    {
        $label = $platform === 'cloudflare' ? 'Cloudflare API token' : 'API key';

        return $interactive ? password($label, required: $default === '') : $default;
    }

    /**
     * Prompt for the provider-key label used to scope cache and routing rows.
     */
    private function labelPrompt(bool $interactive): string
    {
        return $interactive ? text('Label', default: 'Primary', required: true) : 'Primary';
    }

    /**
     * Render a multi-select prompt over cached model choices and return model IDs selected for auto routing.
     *
     * @param  array<string, string>  $options
     * @param  array<int, string>|null  $defaultSelected
     * @return array<int, string>
     */
    private function modelSelectionPrompt(bool $interactive, array $options, ?array $defaultSelected = null): array
    {
        unset($options['auto']);

        if ($options === []) {
            return [];
        }

        $modelIds = array_values(array_map('strval', array_keys($options)));
        $defaultSelected ??= $modelIds;
        $defaultSelected = array_values(array_intersect($defaultSelected, $modelIds));

        if (! $interactive) {
            return $defaultSelected;
        }

        /** @var array<int, string> $selected */
        $selected = multiselect(
            label: 'Which models should participate in random auto routing for this provider key?',
            options: $options,
            default: $defaultSelected,
            scroll: 10,
            required: false,
            hint: 'Selected models are used by auto/random_provider. Unselected cached models remain available for exact model IDs.',
        );

        return array_values(array_map('strval', $selected));
    }
}
