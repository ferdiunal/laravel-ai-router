<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Services\ProviderDefinitionManager;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\warning;

final class ProviderDefinitionAddCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider-definition:add';

    protected $description = 'Add a custom OpenAI-compatible provider definition using Laravel Prompts.';

    public function handle(ProviderDefinitionManager $definitions): int
    {
        $platform = $this->textPrompt('Provider slug', 'custom-openai', required: true);
        $name = $this->textPrompt('Provider name', 'Custom OpenAI Proxy', required: true);
        $baseUrl = $this->textPrompt('OpenAI-compatible base URL', 'https://example.com/custom/v1', required: true);
        $headersJson = $this->textPrompt('Extra headers JSON', '{}');
        $timeoutMs = (int) $this->textPrompt('Timeout in milliseconds', '15000', required: true);
        $requiresPlaceholderKey = $this->confirmPrompt('Can this provider use an anonymous placeholder key?', false);

        $headers = json_decode($headersJson !== '' ? $headersJson : '{}', true);
        if (! is_array($headers)) {
            warning('Invalid headers JSON. Use an object like {"X-Title":"AI Dev API"}.');

            return self::FAILURE;
        }

        try {
            $definition = $definitions->addOpenAiCompatible(
                platform: $platform,
                name: $name,
                baseUrl: $baseUrl,
                headers: $headers,
                timeoutMs: $timeoutMs,
                requiresPlaceholderKey: $requiresPlaceholderKey,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                warning($field.': '.implode(' ', $messages));
            }

            return self::FAILURE;
        }

        info("Added {$definition->platform} ({$definition->name}).");
        outro('Custom provider definition saved. Add a provider key next with ai-dev-api:provider:add.');

        return self::SUCCESS;
    }
}
