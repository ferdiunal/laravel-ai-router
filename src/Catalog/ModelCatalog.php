<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Catalog;

/**
 * Provides the curated built-in model catalog used for fallback routing and model cache enrichment.
 */
final class ModelCatalog
{
    /**
     * Return the curated built-in model catalog rows used to seed package routing metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::model('cohere', 'command-r-plus-08-2024', 'Command R+ (08-2024)', 12, 11, '~1-2M', 131072, rpm: 20, rpd: 33),
            self::model('groq', 'llama-3.3-70b-versatile', 'Llama 3.3 70B', 9, 2, '~15M', 131072, rpm: 30, rpd: 1000, tpm: 6000, tpd: 500000),
            self::model('cerebras', 'qwen3-235b', 'Qwen3 235B', 3, 1, '~30M', 8192, rpm: 30, tpm: 60000, tpd: 1000000),
            self::model('sambanova', 'Meta-Llama-3.3-70B-Instruct', 'Llama 3.3 70B', 6, 9, '~6M', 8192, rpm: 20, tpd: 200000),
            self::model('nvidia', 'meta/llama-3.1-70b-instruct', 'Llama 3.1 70B (NV)', 11, 6, 'credits-based', 131072, rpm: 40, enabled: false),
            self::model('mistral', 'mistral-large-latest', 'Mistral Large 3', 7, 8, '~50-100M', 131072, rpm: 2, tpm: 500000),
            self::model('openrouter', 'qwen/qwen3-coder:free', 'Qwen3 Coder (free)', 3, 9, '~6M', 262144, rpm: 20, rpd: 200),
            self::model('github', 'gpt-4o', 'GPT-4o', 5, 7, '~18M', 8000, rpm: 10, rpd: 50),
            self::model('zhipu', 'glm-4.5-flash', 'GLM-4.5 Flash', 5, 4, '~30M', 131072, tpd: 1000000),
            self::model('ollama', 'gpt-oss:20b', 'GPT-OSS 20B (Ollama)', 8, 6, 'session-based', 131072),
            self::model('kilo', 'qwen/qwen3-coder:free', 'Qwen3 Coder (Kilo free)', 4, 8, '200 req/hr', 262144),
            self::model('pollinations', 'openai-fast', 'OpenAI Fast (Pollinations)', 10, 3, 'anonymous', 32768),
            self::model('llm7', 'gpt-oss-20b', 'GPT-OSS 20B (LLM7)', 10, 4, '100 req/hr', 32768),
        ];
    }

    /**
     * Return the catalog row for the requested model identifier when it is known.
     *
     * @return array<string, mixed>
     */
    private static function model(
        string $platform,
        string $modelId,
        string $displayName,
        int $intelligenceRank,
        int $speedRank,
        string $budgetLabel,
        ?int $contextWindow,
        ?int $rpm = null,
        ?int $rpd = null,
        ?int $tpm = null,
        ?int $tpd = null,
        bool $enabled = true,
    ): array {
        return [
            'platform' => $platform,
            'model_id' => $modelId,
            'display_name' => $displayName,
            'intelligence_rank' => $intelligenceRank,
            'speed_rank' => $speedRank,
            'rpm_limit' => $rpm,
            'rpd_limit' => $rpd,
            'tpm_limit' => $tpm,
            'tpd_limit' => $tpd,
            'budget_label' => $budgetLabel,
            'context_window' => $contextWindow,
            'enabled' => $enabled,
        ];
    }
}
