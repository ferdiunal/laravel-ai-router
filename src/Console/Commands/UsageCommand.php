<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Commands;

use Ferdiunal\LaravelAiRouter\Services\UsageAnalyticsRepository;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

/**
 * Displays package usage analytics summary tables grouped by provider label and model.
 */
final class UsageCommand extends Command
{
    protected $signature = 'laravel-ai-router:usage';

    protected $description = 'Show Laravel AI Router usage statistics.';

    /**
     * Render the selected usage range as summary, provider-label, and model aggregate tables.
     */
    public function handle(UsageAnalyticsRepository $analytics): int
    {
        $range = $this->input->isInteractive()
            ? (string) select('Range', ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'], default: '7d')
            : '7d';

        $summary = $analytics->summary($range);
        table(['Metric', 'Value'], collect($summary)->map(fn (mixed $value, string $key): array => [$key, (string) $value])->values()->all());

        $providerRows = $analytics->byProvider($range)
            ->map(fn ($row): array => [$row->platform, $row->provider_label ?? '-', (string) $row->requests, (string) $row->success_count, (string) $row->input_tokens, (string) $row->output_tokens, (string) round((float) $row->avg_latency_ms)])
            ->all();
        table(['Provider', 'Label', 'Requests', 'Success', 'Input', 'Output', 'Avg ms'], $providerRows);

        $modelRows = $analytics->byModel($range)
            ->map(fn ($row): array => [$row->platform, $row->model_id, (string) $row->requests, (string) $row->success_count, (string) $row->input_tokens, (string) $row->output_tokens])
            ->all();
        table(['Provider', 'Model', 'Requests', 'Success', 'Input', 'Output'], $modelRows);

        return self::SUCCESS;
    }
}
