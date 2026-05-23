<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Adapters;

use Ferdiunal\AiDevApi\Adapters\Contracts\ProviderAdapter;
use Ferdiunal\AiDevApi\Catalog\ProviderCatalog;
use InvalidArgumentException;
use RuntimeException;

final class ProviderAdapterRegistry
{
    public function has(string $platform): bool
    {
        try {
            $definition = ProviderCatalog::get($platform);
        } catch (InvalidArgumentException) {
            return false;
        }

        return in_array($definition['adapter'] ?? null, ['openai-compatible', 'cohere'], true);
    }

    public function for(string $platform): ProviderAdapter
    {
        $definition = ProviderCatalog::get($platform);

        if (($definition['adapter'] ?? null) === 'openai-compatible' || ($definition['adapter'] ?? null) === 'cohere') {
            return new OpenAiCompatibleAdapter(
                platform: $platform,
                name: (string) $definition['name'],
                baseUrl: (string) $definition['base_url'],
                extraHeaders: $platform === 'openrouter' ? array_filter(config('ai-dev-api.providers.openrouter.headers', [])) : [],
                timeoutMs: (int) ($definition['timeout_ms'] ?? 15_000),
            );
        }

        throw new RuntimeException("Provider adapter [{$platform}] is not implemented yet.");
    }
}
