<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Commands;

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelCacheService;
use Illuminate\Console\Command;

use function Laravel\Prompts\table;

/**
 * Lists stored provider keys with masked credentials and model cache metadata.
 */
final class ProviderListCommand extends Command
{
    protected $signature = 'laravel-ai-router:provider:list';

    protected $description = 'List provider API keys with masked API values.';

    /**
     * Render provider keys with labels, masked credentials, status, cache timestamps, and enabled flags.
     */
    public function handle(ProviderModelCacheService $modelCache): int
    {
        $rows = LaravelAiRouterProviderKey::query()->orderBy('platform')->orderBy('label')->get()
            ->map(fn (LaravelAiRouterProviderKey $key): array => [
                (string) $key->getKey(),
                $key->platform,
                $key->label,
                $key->masked_key,
                $key->status,
                $key->enabled ? 'yes' : 'no',
                (string) $modelCache->cachedCountForKey($key),
                optional($key->models_cached_at)->toDateTimeString() ?? '-',
            ])
            ->all();

        table(['ID', 'Provider', 'Label', 'API', 'Status', 'Enabled', 'Models', 'Cached At'], $rows);

        return self::SUCCESS;
    }
}
