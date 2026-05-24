<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Catalog;

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderDefinition;
use Ferdiunal\LaravelAiRouter\Support\ProviderDefinitionValidator;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Builds the provider catalog from built-in providers, config-defined custom providers, and runtime database definitions.
 */
final class ProviderCatalog
{
    /**
     * Return the complete catalog map after applying built-in and runtime sources.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $builtIn = self::builtIn();
        $custom = array_replace(self::customFromDatabase(), self::customFromConfig());

        foreach (array_keys($builtIn) as $platform) {
            unset($custom[$platform]);
        }

        return $builtIn + $custom;
    }

    /**
     * Return the built-in provider catalog definitions shipped with the package.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function builtIn(): array
    {
        return [
            'google' => [
                'name' => 'Google AI Studio',
                'adapter' => 'google-ai-studio',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'requires_placeholder_key' => false,
                'custom' => false,
            ],
            'cohere' => [
                'name' => 'Cohere',
                'adapter' => 'cohere',
                'base_url' => 'https://api.cohere.ai/compatibility/v1',
                'requires_placeholder_key' => false,
                'custom' => false,
            ],
            'groq' => self::openAiCompatible('Groq', 'https://api.groq.com/openai/v1'),
            'cerebras' => self::openAiCompatible('Cerebras', 'https://api.cerebras.ai/v1'),
            'sambanova' => self::openAiCompatible('SambaNova', 'https://api.sambanova.ai/v1'),
            'nvidia' => self::openAiCompatible('NVIDIA NIM', 'https://integrate.api.nvidia.com/v1'),
            'mistral' => self::openAiCompatible('Mistral', 'https://api.mistral.ai/v1'),
            'openrouter' => self::openAiCompatible('OpenRouter', 'https://openrouter.ai/api/v1'),
            'github' => self::openAiCompatible('GitHub Models', 'https://models.github.ai/inference'),
            'cloudflare' => [
                'name' => 'Cloudflare Workers AI',
                'adapter' => 'cloudflare-workers-ai',
                'base_url' => 'https://api.cloudflare.com/client/v4',
                'requires_placeholder_key' => false,
                'custom' => false,
            ],
            'zhipu' => self::openAiCompatible('Zhipu AI', 'https://open.bigmodel.cn/api/paas/v4'),
            'ollama' => self::openAiCompatible('Ollama Cloud', 'https://ollama.com/v1', timeoutMs: 120_000),
            'kilo' => self::openAiCompatible('Kilo Gateway', 'https://api.kilo.ai/api/gateway/v1', requiresPlaceholderKey: true),
            'pollinations' => self::openAiCompatible('Pollinations', 'https://text.pollinations.ai/openai/v1', requiresPlaceholderKey: true),
            'llm7' => self::openAiCompatible('LLM7', 'https://api.llm7.io/v1', requiresPlaceholderKey: true),
        ];
    }

    /**
     * Return a single provider catalog definition or fail when the platform is unknown.
     *
     * @return array<string, mixed>
     */
    public static function get(string $platform): array
    {
        return self::all()[$platform] ?? throw new InvalidArgumentException("Unsupported provider platform [{$platform}].");
    }

    /**
     * Read config-defined custom OpenAI-compatible provider definitions and normalize their metadata.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function customFromConfig(): array
    {
        $configured = config('laravel-ai-router.providers.custom', []);
        if (! is_array($configured)) {
            return [];
        }

        $definitions = [];
        foreach ($configured as $platform => $definition) {
            if (! is_string($platform) || ! is_array($definition)) {
                continue;
            }

            $normalized = ProviderDefinitionValidator::normalizeOpenAiCompatible($platform, $definition);
            if ($normalized !== null) {
                $definitions[$platform] = $normalized;
            }
        }

        return $definitions;
    }

    /**
     * Read enabled runtime custom provider definitions from package storage.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function customFromDatabase(): array
    {
        try {
            if (! Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'))->hasTable('laravel_ai_router_provider_definitions')) {
                return [];
            }

            return LaravelAiRouterProviderDefinition::query()
                ->where('enabled', true)
                ->where('adapter', 'openai-compatible')
                ->orderBy('platform')
                ->get()
                ->mapWithKeys(function (LaravelAiRouterProviderDefinition $definition): array {
                    $normalized = ProviderDefinitionValidator::normalizeOpenAiCompatible($definition->platform, [
                        'name' => $definition->name,
                        'base_url' => $definition->base_url,
                        'headers' => $definition->headers ?? [],
                        'timeout_ms' => $definition->timeout_ms,
                        'requires_placeholder_key' => $definition->requires_placeholder_key,
                    ]);

                    return $normalized === null ? [] : [$definition->platform => $normalized];
                })
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Build a normalized OpenAI-compatible provider definition for catalog use.
     *
     * @return array<string, mixed>
     */
    private static function openAiCompatible(
        string $name,
        string $baseUrl,
        int $timeoutMs = 15_000,
        bool $requiresPlaceholderKey = false,
    ): array {
        return [
            'name' => $name,
            'adapter' => 'openai-compatible',
            'base_url' => $baseUrl,
            'timeout_ms' => $timeoutMs,
            'requires_placeholder_key' => $requiresPlaceholderKey,
            'custom' => false,
        ];
    }
}
