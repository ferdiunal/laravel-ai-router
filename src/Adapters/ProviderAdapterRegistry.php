<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Adapters;

use Ferdiunal\LaravelAiRouter\Adapters\Contracts\ProviderAdapter;
use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;
use Ferdiunal\LaravelAiRouter\Support\ProviderDefinitionValidator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves routable provider adapters and provider-specific metadata from the current provider catalog.
 */
final class ProviderAdapterRegistry
{
    /**
     * Determine whether the provider platform has a routable adapter implementation.
     */
    public function has(string $platform): bool
    {
        try {
            $definition = ProviderCatalog::get($platform);
        } catch (InvalidArgumentException) {
            return false;
        }

        return in_array($definition['adapter'] ?? null, ['openai-compatible', 'cohere', 'google-ai-studio', 'cloudflare-workers-ai'], true);
    }

    /**
     * Resolve the adapter instance for a routable provider platform.
     */
    public function for(string $platform): ProviderAdapter
    {
        $definition = ProviderCatalog::get($platform);

        if (($definition['adapter'] ?? null) === 'google-ai-studio') {
            return new GoogleAiStudioAdapter;
        }

        if (($definition['adapter'] ?? null) === 'cloudflare-workers-ai') {
            return new CloudflareWorkersAiAdapter;
        }

        if (($definition['adapter'] ?? null) === 'openai-compatible' || ($definition['adapter'] ?? null) === 'cohere') {
            return new OpenAiCompatibleAdapter(
                platform: $platform,
                name: (string) $definition['name'],
                baseUrl: (string) $definition['base_url'],
                extraHeaders: $this->extraHeaders($platform, $definition),
                enforcePublicBaseUrl: (bool) ($definition['custom'] ?? false),
                timeoutMs: (int) ($definition['timeout_ms'] ?? 15_000),
                maxStreamLineBytes: max(1024, (int) config('laravel-ai-router.streaming.max_line_bytes', 65_536)),
                maxStreamEventBytes: max(1024, (int) config('laravel-ai-router.streaming.max_event_bytes', 1_048_576)),
            );
        }

        throw new RuntimeException("Provider adapter [{$platform}] is not implemented yet.");
    }

    /**
     * Return sanitized provider-specific headers that are safe to send with upstream requests.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    private function extraHeaders(string $platform, array $definition): array
    {
        $headers = $platform === 'openrouter'
            ? array_filter(config('laravel-ai-router.providers.openrouter.headers', []))
            : ($definition['headers'] ?? []);

        return ProviderDefinitionValidator::sanitizeHeaders(is_array($headers) ? $headers : []);
    }
}
