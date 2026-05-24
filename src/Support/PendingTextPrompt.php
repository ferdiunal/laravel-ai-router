<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Support;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use LogicException;

/**
 * Fluent text prompt builder used by the package-level ai()->using(...) helper.
 */
final class PendingTextPrompt
{
    /**
     * @param  array<int, mixed>  $messages
     * @param  array<int, Tool>  $tools
     * @param  array<int, mixed>  $attachments
     */
    public function __construct(
        private readonly string $provider,
        private readonly ?string $model = null,
        private readonly string $instructions = '',
        private readonly array $messages = [],
        private readonly array $tools = [],
        private readonly ?string $prompt = null,
        private readonly array $attachments = [],
        private readonly ?int $timeout = null,
    ) {}

    /**
     * Set system instructions for the anonymous agent.
     */
    public function instructions(string $instructions): self
    {
        return new self(
            $this->provider,
            $this->model,
            $instructions,
            $this->messages,
            $this->tools,
            $this->prompt,
            $this->attachments,
            $this->timeout,
        );
    }

    /**
     * Include previous conversation messages when invoking the anonymous agent.
     *
     * @param  iterable<int, mixed>  $messages
     */
    public function withMessages(iterable $messages): self
    {
        return new self(
            $this->provider,
            $this->model,
            $this->instructions,
            is_array($messages) ? $messages : iterator_to_array($messages),
            $this->tools,
            $this->prompt,
            $this->attachments,
            $this->timeout,
        );
    }

    /**
     * Attach Laravel AI tools to the anonymous agent.
     *
     * @param  iterable<int, Tool>  $tools
     */
    public function withTools(iterable $tools): self
    {
        return new self(
            $this->provider,
            $this->model,
            $this->instructions,
            $this->messages,
            is_array($tools) ? $tools : iterator_to_array($tools),
            $this->prompt,
            $this->attachments,
            $this->timeout,
        );
    }

    /**
     * Set a timeout, in seconds, for the text request.
     */
    public function timeout(?int $timeout): self
    {
        return new self(
            $this->provider,
            $this->model,
            $this->instructions,
            $this->messages,
            $this->tools,
            $this->prompt,
            $this->attachments,
            $timeout,
        );
    }

    /**
     * Set the user prompt and optional attachments.
     *
     * @param  array<int, mixed>  $attachments
     */
    public function prompt(string $prompt, array $attachments = []): self
    {
        return new self(
            $this->provider,
            $this->model,
            $this->instructions,
            $this->messages,
            $this->tools,
            $prompt,
            $attachments,
            $this->timeout,
        );
    }

    /**
     * Send the prompt and return the full Laravel AI agent response.
     */
    public function response(): AgentResponse
    {
        if ($this->prompt === null) {
            throw new LogicException('Call prompt() before response() or asText().');
        }

        return $this->agent()->prompt(
            $this->prompt,
            $this->attachments,
            provider: $this->provider,
            model: $this->model,
            timeout: $this->timeout,
        );
    }

    /**
     * Send the prompt and return only the response text.
     */
    public function asText(): string
    {
        return (string) $this->response();
    }

    /**
     * Send the prompt and return Laravel AI's streamable response.
     */
    public function stream(): StreamableAgentResponse
    {
        if ($this->prompt === null) {
            throw new LogicException('Call prompt() before stream().');
        }

        return $this->agent()->stream(
            $this->prompt,
            $this->attachments,
            provider: $this->provider,
            model: $this->model,
            timeout: $this->timeout,
        );
    }

    private function agent(): AnonymousAgent
    {
        return new AnonymousAgent($this->instructions, $this->messages, $this->tools);
    }
}
