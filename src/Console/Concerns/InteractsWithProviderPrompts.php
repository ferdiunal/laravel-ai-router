<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Concerns;

use Ferdiunal\AiDevApi\Catalog\ProviderCatalog;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderDefinition;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\search;
use function Laravel\Prompts\text;

trait InteractsWithProviderPrompts
{
    protected function confirmPrompt(string $label, bool $default = true): bool
    {
        return $this->shouldPrompt() ? confirm($label, default: $default) : $default;
    }

    protected function textPrompt(string $label, string $default = '', bool|string $required = false): string
    {
        return $this->shouldPrompt() ? text($label, default: $default, required: $required) : $default;
    }

    protected function passwordPrompt(string $label, string $default = ''): string
    {
        return $this->shouldPrompt() ? password($label, required: true) : $default;
    }

    protected function providerPrompt(string $label = 'Provider'): string
    {
        $options = collect(ProviderCatalog::all())
            ->filter(fn (array $definition): bool => in_array($definition['adapter'] ?? null, ['openai-compatible', 'cohere'], true))
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

    /** @param array<string, string> $options */
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

    protected function keyPrompt(string $label = 'Provider key'): ?AiDevApiProviderKey
    {
        $keys = AiDevApiProviderKey::query()->orderBy('platform')->orderBy('label')->get();

        if ($keys->isEmpty()) {
            return null;
        }

        if (! $this->shouldPrompt()) {
            return $keys->first();
        }

        $selected = search(
            label: $label,
            options: fn (string $value): array => $keys
                ->filter(fn (AiDevApiProviderKey $key): bool => str_contains(strtolower($key->platform.' '.$key->label), strtolower($value)))
                ->mapWithKeys(fn (AiDevApiProviderKey $key): array => [(int) $key->getKey() => "{$key->platform} / {$key->label} / {$key->masked_key}"])
                ->all(),
            placeholder: 'Search provider or label',
        );

        return $keys->firstWhere('id', (int) $selected);
    }

    protected function definitionPrompt(string $label = 'Custom provider definition'): ?AiDevApiProviderDefinition
    {
        $definitions = AiDevApiProviderDefinition::query()->orderBy('platform')->get();

        if ($definitions->isEmpty()) {
            return null;
        }

        if (! $this->shouldPrompt()) {
            return $definitions->first();
        }

        $selected = search(
            label: $label,
            options: fn (string $value): array => $definitions
                ->filter(fn (AiDevApiProviderDefinition $definition): bool => str_contains(strtolower($definition->platform.' '.$definition->name), strtolower($value)))
                ->mapWithKeys(fn (AiDevApiProviderDefinition $definition): array => [(int) $definition->getKey() => "{$definition->platform} / {$definition->name} / {$definition->base_url}"])
                ->all(),
            placeholder: 'Search provider slug or name',
        );

        return $definitions->firstWhere('id', (int) $selected);
    }

    protected function shouldPrompt(): bool
    {
        return $this->input->isInteractive() && ! app()->runningUnitTests();
    }
}
