<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;

it('defines requested openai-compatible provider endpoints', function (string $platform, string $baseUrl) {
    expect(ProviderCatalog::get($platform)['base_url'])->toBe($baseUrl);
})->with([
    ['google', 'https://generativelanguage.googleapis.com/v1beta'],
    ['groq', 'https://api.groq.com/openai/v1'],
    ['cerebras', 'https://api.cerebras.ai/v1'],
    ['sambanova', 'https://api.sambanova.ai/v1'],
    ['nvidia', 'https://integrate.api.nvidia.com/v1'],
    ['mistral', 'https://api.mistral.ai/v1'],
    ['openrouter', 'https://openrouter.ai/api/v1'],
    ['github', 'https://models.github.ai/inference'],
    ['cloudflare', 'https://api.cloudflare.com/client/v4'],
    ['zhipu', 'https://open.bigmodel.cn/api/paas/v4'],
    ['ollama', 'https://ollama.com/v1'],
    ['kilo', 'https://api.kilo.ai/api/gateway/v1'],
    ['pollinations', 'https://text.pollinations.ai/openai/v1'],
    ['llm7', 'https://api.llm7.io/v1'],
]);

it('uses a 120 second timeout for ollama catalog requests', function () {
    expect(ProviderCatalog::get('ollama')['timeout_ms'])->toBe(120_000);
});
