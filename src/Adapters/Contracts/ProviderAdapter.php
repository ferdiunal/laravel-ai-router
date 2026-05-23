<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Adapters\Contracts;

use Generator;

/**
 * Defines the adapter contract used to normalize provider-specific model, completion, streaming, and credential validation operations.
 */
interface ProviderAdapter
{
    /**
     * Return the provider platform slug represented by this adapter.
     */
    public function platform(): string;

    /**
     * Return the human-readable provider name represented by this adapter.
     */
    public function name(): string;

    /**
     * Send a non-streaming text completion request to the upstream provider and return the normalized response payload.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function complete(string $apiKey, array $messages, string $modelId, array $options = [], ?int $timeout = null): array;

    /**
     * Open a streaming text completion request and yield decoded provider chunks within configured buffer limits.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(string $apiKey, array $messages, string $modelId, array $options = [], ?int $timeout = null): Generator;

    /**
     * Return upstream model metadata supported by the provider adapter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function models(string $apiKey): array;

    /**
     * Validate provider credentials without exposing the raw key in output or persisted errors.
     */
    public function validateKey(string $apiKey): bool;
}
