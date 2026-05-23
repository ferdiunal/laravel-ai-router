<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Catalog;

use InvalidArgumentException;

final class ProviderCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'google' => [
                'name' => 'Google AI Studio',
                'adapter' => 'google',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'requires_placeholder_key' => false,
            ],
            'cloudflare' => [
                'name' => 'Cloudflare Workers AI',
                'adapter' => 'cloudflare',
                'key_format' => 'account_id:api_token',
                'requires_placeholder_key' => false,
            ],
            'cohere' => [
                'name' => 'Cohere',
                'adapter' => 'cohere',
                'base_url' => 'https://api.cohere.ai/compatibility/v1',
                'requires_placeholder_key' => false,
            ],
            'groq' => self::openAiCompatible('Groq', 'https://api.groq.com/openai/v1'),
            'cerebras' => self::openAiCompatible('Cerebras', 'https://api.cerebras.ai/v1'),
            'sambanova' => self::openAiCompatible('SambaNova', 'https://api.sambanova.ai/v1'),
            'nvidia' => self::openAiCompatible('NVIDIA NIM', 'https://integrate.api.nvidia.com/v1'),
            'mistral' => self::openAiCompatible('Mistral', 'https://api.mistral.ai/v1'),
            'openrouter' => self::openAiCompatible('OpenRouter', 'https://openrouter.ai/api/v1'),
            'github' => self::openAiCompatible('GitHub Models', 'https://models.github.ai/inference'),
            'zhipu' => self::openAiCompatible('Zhipu AI', 'https://open.bigmodel.cn/api/paas/v4'),
            'ollama' => self::openAiCompatible('Ollama Cloud', 'https://ollama.com/v1', timeoutMs: 120_000),
            'kilo' => self::openAiCompatible('Kilo Gateway', 'https://api.kilo.ai/api/gateway/v1', requiresPlaceholderKey: true),
            'pollinations' => self::openAiCompatible('Pollinations', 'https://text.pollinations.ai/openai/v1', requiresPlaceholderKey: true),
            'llm7' => self::openAiCompatible('LLM7', 'https://api.llm7.io/v1', requiresPlaceholderKey: true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $platform): array
    {
        return self::all()[$platform] ?? throw new InvalidArgumentException("Unsupported provider platform [{$platform}].");
    }

    /**
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
        ];
    }
}
