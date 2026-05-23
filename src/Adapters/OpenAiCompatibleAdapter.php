<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Adapters;

use Ferdiunal\AiDevApi\Adapters\Contracts\ProviderAdapter;
use Ferdiunal\AiDevApi\Exceptions\ProviderAuthenticationException;
use Ferdiunal\AiDevApi\Support\ProviderDefinitionValidator;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class OpenAiCompatibleAdapter implements ProviderAdapter
{
    /** @param array<string, string> $extraHeaders */
    public function __construct(
        private readonly string $platform,
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly array $extraHeaders = [],
        private readonly bool $enforcePublicBaseUrl = false,
        private readonly ?string $validateUrl = null,
        private readonly int $timeoutMs = 15_000,
        private readonly int $maxStreamLineBytes = 65_536,
        private readonly int $maxStreamEventBytes = 1_048_576,
    ) {}

    public function platform(): string
    {
        return $this->platform;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function complete(string $apiKey, array $messages, string $modelId, array $options = [], ?int $timeout = null): array
    {
        $url = $this->endpoint('chat/completions');

        $response = Http::timeout($this->timeoutSeconds($timeout))
            ->withHeaders($this->safeExtraHeaders())
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

    public function stream(string $apiKey, array $messages, string $modelId, array $options = [], ?int $timeout = null): Generator
    {
        $url = $this->endpoint('chat/completions');

        $response = Http::timeout($this->timeoutSeconds($timeout))
            ->withHeaders($this->safeExtraHeaders())
            ->withToken($apiKey)
            ->accept('text/event-stream')
            ->withOptions([...$this->requestOptions($url), 'stream' => true])
            ->post($url, $this->streamPayload($messages, $modelId, $options));

        $this->throwIfUnsuccessful($response, 'streaming API error');

        yield from $this->parseServerSentEvents($response->toPsrResponse()->getBody());
    }

    public function models(string $apiKey): array
    {
        $url = $this->endpoint('models');

        $response = Http::timeout($this->timeoutSeconds())
            ->withHeaders($this->safeExtraHeaders())
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

    public function validateKey(string $apiKey): bool
    {
        $url = $this->validationEndpoint();

        $response = Http::timeout(10)
            ->withHeaders($this->safeExtraHeaders())
            ->withToken($apiKey)
            ->withOptions($this->requestOptions($url))
            ->get($url);

        return ! in_array($response->status(), [401, 403], true);
    }

    private function endpoint(string $path): string
    {
        $baseUrl = $this->enforcePublicBaseUrl
            ? $this->validatedPublicUrl($this->baseUrl, 'base')
            : $this->baseUrl;

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

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

    private function validatedPublicUrl(string $url, string $kind): string
    {
        $validatedUrl = ProviderDefinitionValidator::normalizeBaseUrl($url, requirePublicDns: true);

        if ($validatedUrl === null) {
            throw new RuntimeException("Custom provider [{$this->platform}] {$kind} URL must be a public HTTPS URL that resolves only to public addresses.");
        }

        return $validatedUrl;
    }

    /** @return array<string, mixed> */
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

    private function urlHostIsIpAddress(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /** @return list<string> */
    private function curlResolveEntries(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);

        return collect(ProviderDefinitionValidator::publicAddressesForBaseUrl($url))
            ->map(fn (string $address): string => sprintf(
                '%s:%d:%s',
                strtolower(rtrim($host, '.')),
                $port,
                str_contains($address, ':') ? '['.$address.']' : $address,
            ))
            ->values()
            ->all();
    }

    /** @return array<string, string> */
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

    private function timeoutSeconds(?int $timeout = null): float
    {
        if ($timeout !== null) {
            return max(1, $timeout);
        }

        return max(1, $this->timeoutMs / 1000);
    }

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

    /** @return Generator<int, array<string, mixed>> */
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
