<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Concerns;

use Ferdiunal\LaravelAiRouter\Adapters\ProviderAdapterRegistry;
use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderDefinition;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\search;
use function Laravel\Prompts\text;

/**
 * Provides Laravel Prompts helpers shared by provider and custom-provider Artisan commands.
 */
trait InteractsWithProviderPrompts
{
    /**
     * Render a confirmation prompt when interactive input is available, otherwise return the deterministic default.
     */
    protected function confirmPrompt(string $label, bool $default = true): bool
    {
        return $this->shouldPrompt() ? confirm($label, default: $default) : $default;
    }

    /**
     * Render a text prompt when interactive input is available, otherwise return the deterministic default.
     */
    protected function textPrompt(string $label, string $default = '', bool|string $required = false): string
    {
        return $this->shouldPrompt() ? text($label, default: $default, required: $required) : $default;
    }

    /**
     * Render a hidden input prompt for provider credentials without echoing the raw key.
     */
    protected function passwordPrompt(string $label, string $default = ''): string
    {
        return $this->shouldPrompt() ? password($label, required: true) : $default;
    }

    /**
     * Render a searchable provider prompt restricted to routable provider definitions.
     */
    protected function providerPrompt(string $label = 'Provider'): string
    {
        $adapters = app(ProviderAdapterRegistry::class);

        $options = collect(ProviderCatalog::all())
            ->filter(fn (array $definition, string $platform): bool => $adapters->has($platform))
            ->mapWithKeys(fn (array $definition, string $platform): array => [$platform => "{$definition['name']} ({$platform})"])
            ->all();

        if (! $this->shouldPrompt()) {
            return (string) array_key_first($options);
        }

        return (string) search(
            label: $label,
            options: fn (string $value): array => collect($options)
                ->filter(fn (string $description, string $platform): bool => str_contains(strtolower($platform.' '.$description), strtolower($value)))
                ->all(),
            placeholder: 'Search provider name or platform',
            scroll: 10,
        );
    }

    /**
     * Render a searchable model prompt over cached model choices and return the selected model identifier.
     *
     * @param  array<string, string>  $options
     */
    protected function modelPrompt(array $options, string $label = 'Which model should be default?', string $default = 'auto'): string
    {
        if ($options === []) {
            return 'auto';
        }

        if (! array_key_exists($default, $options)) {
            $default = (string) array_key_first($options);
        }

        if (! $this->shouldPrompt()) {
            return $default;
        }

        return (string) search(
            label: $label,
            options: fn (string $value): array => collect($options)
                ->filter(fn (string $description, string $modelId): bool => str_contains(strtolower($modelId.' '.$description), strtolower($value)))
                ->all(),
            placeholder: 'Search model id, label, provider, or capability',
            scroll: 10,
        );
    }

    /**
     * Render a multi-select model prompt over cached model choices.
     *
     * @param  array<string, string>  $options
     * @param  array<int, string>  $defaultSelected
     * @return array<int, string>
     */
    protected function multiModelPrompt(array $options, array $defaultSelected = [], string $label = 'Which models should participate in random auto routing?'): array
    {
        unset($options['auto']);

        if ($options === []) {
            return [];
        }

        $modelIds = array_values(array_map('strval', array_keys($options)));
        $defaultSelected = array_values(array_intersect($defaultSelected, $modelIds));

        if (! $this->shouldPrompt()) {
            return $defaultSelected;
        }

        /** @var array<int, string> $selected */
        $selected = multiselect(
            label: $label,
            options: $options,
            default: $defaultSelected,
            scroll: 10,
            required: false,
            hint: 'Selected models are used by auto/random_provider. Unselected cached models remain available for exact model IDs.',
        );

        return array_values(array_map('strval', $selected));
    }

    /**
     * Render a searchable provider-key prompt using masked credentials only.
     */
    protected function keyPrompt(string $label = 'Provider key'): ?LaravelAiRouterProviderKey
    {
        $keys = LaravelAiRouterProviderKey::query()->orderBy('platform')->orderBy('label')->get();

        if ($keys->isEmpty()) {
            return null;
        }

        if (! $this->shouldPrompt()) {
            return $keys->first();
        }

        $selected = search(
            label: $label,
            options: fn (string $value): array => $keys
                ->filter(fn (LaravelAiRouterProviderKey $key): bool => str_contains(strtolower($key->platform.' '.$key->label), strtolower($value)))
                ->mapWithKeys(fn (LaravelAiRouterProviderKey $key): array => [(int) $key->getKey() => "{$key->platform} / {$key->label} / {$key->masked_key}"])
                ->all(),
            placeholder: 'Search provider or label',
        );

        return $keys->firstWhere('id', (int) $selected);
    }

    /**
     * Render a searchable custom-provider definition prompt for runtime definition commands.
     */
    protected function definitionPrompt(string $label = 'Custom provider definition'): ?LaravelAiRouterProviderDefinition
    {
        $definitions = LaravelAiRouterProviderDefinition::query()->orderBy('platform')->get();

        if ($definitions->isEmpty()) {
            return null;
        }

        if (! $this->shouldPrompt()) {
            return $definitions->first();
        }

        $selected = search(
            label: $label,
            options: fn (string $value): array => $definitions
                ->filter(fn (LaravelAiRouterProviderDefinition $definition): bool => str_contains(strtolower($definition->platform.' '.$definition->name), strtolower($value)))
                ->mapWithKeys(fn (LaravelAiRouterProviderDefinition $definition): array => [(int) $definition->getKey() => "{$definition->platform} / {$definition->name} / {$definition->base_url}"])
                ->all(),
            placeholder: 'Search provider slug or name',
        );

        return $definitions->firstWhere('id', (int) $selected);
    }

    /**
     * Determine whether Laravel Prompts should be used for the current console execution context.
     */
    protected function shouldPrompt(): bool
    {
        return $this->input->isInteractive() && ! app()->runningUnitTests();
    }
}
