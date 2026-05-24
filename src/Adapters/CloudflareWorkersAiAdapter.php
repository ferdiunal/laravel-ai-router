<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Adapters;

use Ferdiunal\LaravelAiRouter\Adapters\Contracts\ProviderAdapter;
use Ferdiunal\LaravelAiRouter\Exceptions\ProviderAuthenticationException;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Implements Cloudflare Workers AI routing through its account-scoped OpenAI-compatible chat endpoint.
 */
final class CloudflareWorkersAiAdapter implements ProviderAdapter
{
    private const PLATFORM = 'cloudflare';

    private const NAME = 'Cloudflare Workers AI';

    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * Return the provider platform slug represented by this adapter.
     */
    public function platform(): string
    {
        return self::PLATFORM;
    }

    /**
     * Return the human-readable provider name represented by this adapter.
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Send a non-streaming chat completion request to Cloudflare's account-scoped OpenAI-compatible endpoint.
     */
    public function complete(string $apiKey, array $messages, string $modelId, array $options = [], ?int $timeout = null): array
    {
        $credentials = $this->parseKey($apiKey);

        return $this->openAiCompatibleAdapter($credentials['account_id'])
            ->complete($credentials['token'], $this->normalizeMessages($messages), $modelId, $options, $timeout);
    }

    /**
     * Open a streaming chat completion request to Cloudflare's account-scoped OpenAI-compatible endpoint.
     */
    public function stream(string $apiKey, array $messages, string $modelId, array $options = [], ?int $timeout = null): Generator
    {
        $credentials = $this->parseKey($apiKey);

        yield from $this->openAiCompatibleAdapter($credentials['account_id'])
            ->stream($credentials['token'], $this->normalizeMessages($messages), $modelId, $options, $timeout);
    }

    /**
     * Return Cloudflare Workers AI model metadata when the account model-search endpoint is available.
     */
    public function models(string $apiKey): array
    {
        $credentials = $this->parseKey($apiKey);
        $url = self::API_BASE.'/accounts/'.$credentials['account_id'].'/ai/models/search';

        $response = Http::timeout(15)
            ->withToken($credentials['token'])
            ->acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        $this->throwIfUnsuccessful($response, 'models API error');

        $data = $response->json();

        return collect((array) data_get($data, 'result', []))
            ->filter(fn (mixed $model): bool => is_array($model) && filled($model['name'] ?? $model['id'] ?? null))
            ->map(function (array $model): array {
                $modelId = (string) ($model['name'] ?? $model['id']);

                return [
                    'model_id' => $modelId,
                    'display_name' => (string) ($model['display_name'] ?? $model['displayName'] ?? $model['name'] ?? $model['id']),
                    'context_window' => $this->integerMetadata($model, ['context_window', 'contextWindow', 'inputTokenLimit', 'max_input_tokens', 'properties.context_window', 'properties.max_input_tokens']),
                    'supports_tools' => null,
                    'raw_metadata' => $model,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Validate a Cloudflare token without requiring account-specific AI permissions to be exercised.
     */
    public function validateKey(string $apiKey): bool
    {
        $credentials = $this->parseKey($apiKey);

        $response = Http::timeout(10)
            ->withToken($credentials['token'])
            ->withOptions(['allow_redirects' => false])
            ->get(self::API_BASE.'/user/tokens/verify');

        if (in_array($response->status(), [401, 403], true)) {
            return false;
        }

        if (! $response->successful()) {
            return true;
        }

        $data = $response->json();

        return (bool) data_get($data, 'success') && data_get($data, 'result.status') === 'active';
    }

    /**
     * Parse the package-level Cloudflare key shape: account_id:api_token.
     *
     * @return array{account_id: string, token: string}
     */
    private function parseKey(string $apiKey): array
    {
        $separator = strpos($apiKey, ':');

        if ($separator === false) {
            throw new RuntimeException('Cloudflare key must be in format "account_id:api_token".');
        }

        $accountId = trim(substr($apiKey, 0, $separator));
        $token = trim(substr($apiKey, $separator + 1));

        if ($accountId === '' || $token === '') {
            throw new RuntimeException('Cloudflare key must be in format "account_id:api_token".');
        }

        return ['account_id' => $accountId, 'token' => $token];
    }

    /**
     * Cloudflare rejects OpenAI-style assistant tool messages with content=null; send an empty string instead.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMessages(array $messages): array
    {
        return array_map(function (array $message): array {
            if (($message['content'] ?? null) === null) {
                $message['content'] = '';
            }

            return $message;
        }, $messages);
    }

    /**
     * Build the reusable OpenAI-compatible adapter for one Cloudflare account.
     */
    private function openAiCompatibleAdapter(string $accountId): OpenAiCompatibleAdapter
    {
        return new OpenAiCompatibleAdapter(
            platform: self::PLATFORM,
            name: self::NAME,
            baseUrl: self::API_BASE.'/accounts/'.$accountId.'/ai/v1',
            timeoutMs: 15_000,
            maxStreamLineBytes: max(1024, (int) config('laravel-ai-router.streaming.max_line_bytes', 65_536)),
            maxStreamEventBytes: max(1024, (int) config('laravel-ai-router.streaming.max_event_bytes', 1_048_576)),
        );
    }

    /**
     * Convert unsuccessful upstream HTTP responses into routed provider exceptions.
     */
    private function throwIfUnsuccessful(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) data_get($response->json(), 'error.message', data_get($response->json(), 'errors.0.message', $response->reason()));

        if (in_array($response->status(), [401, 403], true)) {
            throw new ProviderAuthenticationException(self::NAME, $response->status(), $message);
        }

        throw new RuntimeException(self::NAME.' '.$context.' '.$response->status().': '.$message, $response->status());
    }

    /**
     * Return the first integer-like metadata field found at one of the candidate paths.
     *
     * @param  array<string, mixed>  $model
     * @param  array<int, string>  $paths
     */
    private function integerMetadata(array $model, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($model, $path);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
