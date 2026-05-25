<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Adapters;

use Ferdiunal\LaravelAiRouter\Adapters\Contracts\ProviderAdapter;
use Ferdiunal\LaravelAiRouter\Exceptions\ProviderAuthenticationException;
use Ferdiunal\LaravelAiRouter\Support\ProviderDefinitionValidator;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Implements the ProviderAdapter contract for OpenAI-compatible HTTP APIs with bounded request, response, and SSE parsing behavior.
 */
final class OpenAiCompatibleAdapter implements ProviderAdapter
{
    /**
     * Initialize the adapter with provider metadata and sanitized extra headers.
     *
     * @param  array<string, string>  $extraHeaders
     */
    public function __construct(
        private readonly string $platform,
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly array $extraHeaders = [],
        private readonly bool $enforcePublicBaseUrl = false,
        private readonly ?string $validateUrl = null,
        private readonly int $timeoutMs = 15_000,
        private readonly string $validationMethod = 'models',
        private readonly ?string $validationModel = null,
        private readonly int $maxStreamLineBytes = 65_536,
        private readonly int $maxStreamEventBytes = 1_048_576,
    ) {}

    /**
     * Return the provider platform slug represented by this adapter.
     */
    public function platform(): string
    {
        return $this->platform;
    }

    /**
     * Return the human-readable provider name represented by this adapter.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Send a non-streaming text completion request to the upstream provider and return the normalized response payload.
     */
    public function complete(string $apiKey, array $messages, string $modelId, array $options = [], ?int $timeout = null): array
    {
        $url = $this->endpoint('chat/completions');

        $response = Http::timeout($this->timeoutSeconds($timeout))
            ->withHeaders($this->requestHeaders())
            ->withToken($apiKey)
            ->withOptions($this->requestOptions($url))
            ->acceptJson()
            ->post($url, $this->payload($messages, $modelId, $options));

        $this->throwIfUnsuccessful($response, 'API error');

        $data = $response->json();
        $this->normalizeChoices($data);
        $data['_routed_via'] = ['platform' => $this->platform, 'model' => $modelId];

        return $data;
    }

    /**
     * Open a streaming text completion request and yield decoded provider chunks within configured buffer limits.
     */
    public function stream(string $apiKey, array $messages, string $modelId, array $options = [], ?int $timeout = null): Generator
    {
        $url = $this->endpoint('chat/completions');

        $response = Http::timeout($this->timeoutSeconds($timeout))
            ->withHeaders($this->requestHeaders())
            ->withToken($apiKey)
            ->accept('text/event-stream')
            ->withOptions([...$this->requestOptions($url), 'stream' => true])
            ->post($url, $this->streamPayload($messages, $modelId, $options));

        $this->throwIfUnsuccessful($response, 'streaming API error');

        yield from $this->parseServerSentEvents($response->toPsrResponse()->getBody());
    }

    /**
     * Fetch model candidates from the upstream OpenAI-compatible models endpoint.
     */
    public function models(string $apiKey): array
    {
        $url = $this->endpoint('models');

        $response = Http::timeout($this->timeoutSeconds())
            ->withHeaders($this->requestHeaders())
            ->withToken($apiKey)
            ->withOptions($this->requestOptions($url))
            ->acceptJson()
            ->get($url);

        $this->throwIfUnsuccessful($response, 'models API error');

        $data = $response->json();

        return collect((array) data_get($data, 'data', []))
            ->filter(fn (mixed $model): bool => is_array($model) && filled($model['id'] ?? null))
            ->map(fn (array $model): array => [
                'model_id' => (string) $model['id'],
                'display_name' => (string) ($model['name'] ?? $model['id']),
                'context_window' => isset($model['context_length']) ? (int) $model['context_length'] : null,
                'supports_tools' => data_get($model, 'supported_parameters') ? in_array('tools', (array) data_get($model, 'supported_parameters'), true) : null,
                'raw_metadata' => $model,
            ])
            ->values()
            ->all();
    }

    /**
     * Validate provider credentials without exposing the raw key in output or persisted errors.
     */
    public function validateKey(string $apiKey): bool
    {
        if ($this->validationMethod === 'chat') {
            return $this->validateKeyWithChat($apiKey);
        }

        $url = $this->validationEndpoint();

        $response = Http::timeout(10)
            ->withHeaders($this->requestHeaders())
            ->withToken($apiKey)
            ->withOptions($this->requestOptions($url))
            ->get($url);

        return ! in_array($response->status(), [401, 403], true);
    }

    /**
     * Validate provider credentials by sending a minimal non-streaming chat completion probe.
     */
    private function validateKeyWithChat(string $apiKey): bool
    {
        $modelId = trim((string) $this->validationModel);
        if ($modelId === '') {
            throw new RuntimeException("Provider [{$this->platform}] chat validation requires a validation model.");
        }

        $url = $this->endpoint('chat/completions');

        $response = Http::timeout(10)
            ->withHeaders($this->requestHeaders())
            ->withToken($apiKey)
            ->withOptions($this->requestOptions($url))
            ->acceptJson()
            ->post($url, [
                'model' => $modelId,
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 1,
            ]);

        if (in_array($response->status(), [401, 403], true)) {
            return false;
        }

        if (! $response->successful()) {
            $this->throwIfUnsuccessful($response, 'chat validation API error');
        }

        return true;
    }

    /**
     * Build an absolute OpenAI-compatible endpoint URL from the configured base URL and request path.
     */
    private function endpoint(string $path): string
    {
        $baseUrl = $this->enforcePublicBaseUrl
            ? $this->validatedPublicUrl($this->baseUrl, 'base')
            : $this->baseUrl;

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * Resolve the credential-validation URL, falling back to the provider models endpoint when no override is configured.
     */
    private function validationEndpoint(): string
    {
        if ($this->validateUrl === null) {
            return $this->endpoint('models');
        }

        if (! $this->enforcePublicBaseUrl) {
            return $this->validateUrl;
        }

        return $this->validatedPublicUrl($this->validateUrl, 'validation');
    }

    /**
     * Normalize and enforce public-HTTPS DNS validation for custom provider request URLs.
     */
    private function validatedPublicUrl(string $url, string $kind): string
    {
        $validatedUrl = ProviderDefinitionValidator::normalizeBaseUrl($url, requirePublicDns: true);

        if ($validatedUrl === null) {
            throw new RuntimeException("Custom provider [{$this->platform}] {$kind} URL must be a public HTTPS URL that resolves only to public addresses.");
        }

        return $validatedUrl;
    }

    /**
     * Build the HTTP client options used for an upstream OpenAI-compatible request.
     *
     * @return array<string, mixed>
     */
    private function requestOptions(string $url): array
    {
        $options = ['allow_redirects' => false];

        if (! $this->enforcePublicBaseUrl) {
            return $options;
        }

        if ($this->urlHostIsIpAddress($url)) {
            return $options;
        }

        if (! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException("Custom provider [{$this->platform}] request URL cannot be pinned to validated public DNS addresses.");
        }

        $curlResolveEntries = $this->curlResolveEntries($url);
        if ($curlResolveEntries === []) {
            throw new RuntimeException("Custom provider [{$this->platform}] request URL must resolve to public addresses before dispatch.");
        }

        $options['curl'] = [(int) constant('CURLOPT_RESOLVE') => $curlResolveEntries];

        return $options;
    }

    /**
     * Determine whether the request URL already targets a literal IP address instead of a DNS hostname.
     */
    private function urlHostIsIpAddress(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Build cURL DNS pinning entries from previously validated public provider IP addresses.
     *
     * @return list<string>
     */
    private function curlResolveEntries(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);

        $addresses = ProviderDefinitionValidator::publicAddressesForBaseUrl($url);
        $ipv4Addresses = array_values(array_filter(
            $addresses,
            fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        ));
        $addressesToPin = $ipv4Addresses !== [] ? $ipv4Addresses : $addresses;

        return collect($addressesToPin)
            ->map(fn (string $address): string => sprintf(
                '%s:%d:%s',
                strtolower(rtrim($host, '.')),
                $port,
                str_contains($address, ':') ? '['.$address.']' : $address,
            ))
            ->values()
            ->all();
    }

    /**
     * Return custom provider metadata headers after removing names that could override package authentication.
     *
     * @return array<string, string>
     */
    private function safeExtraHeaders(): array
    {
        $headers = [];

        foreach ($this->extraHeaders as $name => $value) {
            if (! is_string($name)) {
                continue;
            }

            foreach (ProviderDefinitionValidator::sanitizeHeaders([$name => $value]) as $sanitizedName => $sanitizedValue) {
                $headers[$sanitizedName] = $sanitizedValue;
            }
        }

        return $headers;
    }

    /**
     * Return request headers for OpenAI-compatible HTTP calls while forcing identity encoding.
     *
     * Some compatible gateways advertise gzip while returning plain JSON; asking for identity
     * keeps cURL/Guzzle from failing during automatic decompression.
     *
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        $headers = [];

        foreach ($this->safeExtraHeaders() as $name => $value) {
            if (strtolower($name) === 'accept-encoding') {
                continue;
            }

            $headers[$name] = $value;
        }

        $headers['Accept-Encoding'] = 'identity';

        return $headers;
    }

    /**
     * Resolve the HTTP timeout in seconds from the per-request override or the adapter default.
     */
    private function timeoutSeconds(?int $timeout = null): float
    {
        if ($timeout !== null) {
            return max(1, $timeout);
        }

        return max(1, $this->timeoutMs / 1000);
    }

    /**
     * Convert unsuccessful upstream HTTP responses into authentication-specific or generic provider exceptions.
     */
    private function throwIfUnsuccessful(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) data_get($response->json(), 'error.message', $response->reason());

        if (in_array($response->status(), [401, 403], true)) {
            throw new ProviderAuthenticationException($this->name, $response->status(), $message);
        }

        throw new RuntimeException("{$this->name} {$context} {$response->status()}: {$message}", $response->status());
    }

    /**
     * Build the OpenAI-compatible chat completion payload for non-streaming requests.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function payload(array $messages, string $modelId, array $options): array
    {
        return Arr::whereNotNull([
            'model' => $modelId,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
            'top_p' => $options['top_p'] ?? null,
            'tools' => $options['tools'] ?? null,
            'tool_choice' => $options['tool_choice'] ?? null,
            'parallel_tool_calls' => $options['parallel_tool_calls'] ?? null,
            'response_format' => $options['response_format'] ?? null,
        ]);
    }

    /**
     * Build the OpenAI-compatible streaming payload with usage reporting enabled.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function streamPayload(array $messages, string $modelId, array $options): array
    {
        return [
            ...$this->payload($messages, $modelId, $options),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];
    }

    /**
     * Parse OpenAI-compatible SSE lines into decoded event payloads while enforcing aggregate byte limits.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function parseServerSentEvents(StreamInterface $streamBody): Generator
    {
        $dataLines = [];
        $eventBytes = 0;

        while (! $streamBody->eof()) {
            $line = rtrim($this->readLine($streamBody), "\r\n");

            if ($line === '') {
                $event = $this->decodeServerSentEvent($dataLines);
                $dataLines = [];
                $eventBytes = 0;

                if ($event === '__done__') {
                    return;
                }

                if (is_array($event)) {
                    yield $event;
                }

                continue;
            }

            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $dataLine = ltrim(substr($line, 5), ' ');
            $eventBytes += strlen($dataLine) + 1;

            if ($eventBytes > $this->maxStreamEventBytes) {
                throw new RuntimeException("SSE event exceeded the configured {$this->maxStreamEventBytes} byte limit.");
            }

            $dataLines[] = $dataLine;
        }

        $event = $this->decodeServerSentEvent($dataLines);

        if (is_array($event)) {
            yield $event;
        }
    }

    /**
     * Decode accumulated SSE data lines into a provider chunk, done sentinel, or ignored malformed event.
     *
     * @param  array<int, string>  $dataLines
     * @return array<string, mixed>|'__done__'|null
     */
    private function decodeServerSentEvent(array $dataLines): array|string|null
    {
        if ($dataLines === []) {
            return null;
        }

        $payload = trim(implode("\n", $dataLines));

        if ($payload === '[DONE]') {
            return '__done__';
        }

        $decoded = json_decode($payload, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }

    /**
     * Read one SSE line from the provider stream while enforcing the configured line byte limit.
     */
    private function readLine(StreamInterface $streamBody): string
    {
        $buffer = '';

        while (! $streamBody->eof()) {
            $byte = $streamBody->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if (strlen($buffer) > $this->maxStreamLineBytes) {
                throw new RuntimeException("SSE line exceeded the configured {$this->maxStreamLineBytes} byte limit.");
            }

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    /**
     * Normalize provider choice message content so Laravel AI receives a string when possible.
     *
     * @param  array<string, mixed>  $data
     */
    private function normalizeChoices(array &$data): void
    {
        if (! isset($data['choices']) || ! is_array($data['choices'])) {
            return;
        }

        foreach ($data['choices'] as &$choice) {
            if (! isset($choice['message']) || ! is_array($choice['message'])) {
                continue;
            }

            $message = &$choice['message'];

            if (isset($message['content']) && is_array($message['content'])) {
                $message['content'] = collect($message['content'])
                    ->map(fn (mixed $segment): string => is_array($segment) ? (string) ($segment['text'] ?? '') : (string) $segment)
                    ->implode('');
            }

            $hasToolCalls = isset($message['tool_calls']) && is_array($message['tool_calls']) && count($message['tool_calls']) > 0;

            if (! $hasToolCalls && (($message['content'] ?? null) === '' || ($message['content'] ?? null) === null)) {
                $foldedReasoning = $message['reasoning_content'] ?? $message['reasoning'] ?? null;

                if (is_string($foldedReasoning) && $foldedReasoning !== '') {
                    $message['content'] = $foldedReasoning;
                }
            }
        }
        unset($choice, $message);
    }
}
