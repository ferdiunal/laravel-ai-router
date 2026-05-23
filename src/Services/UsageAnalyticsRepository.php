<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Services;

use Ferdiunal\AiDevApi\Models\AiDevApiRequest;
use Illuminate\Support\Collection;

/**
 * Builds usage analytics summaries from package-owned request rows.
 */
final class UsageAnalyticsRepository
{
    /**
     * Return aggregate usage totals for requests recorded after the configured cutoff.
     *
     * @return array<string, mixed>
     */
    public function summary(string $range = '7d'): array
    {
        $query = AiDevApiRequest::query()->where('created_at', '>=', $this->since($range));

        $total = (clone $query)->count();
        $success = (clone $query)->where('status', 'success')->count();
        $input = (int) (clone $query)->sum('input_tokens');
        $output = (int) (clone $query)->sum('output_tokens');
        $latency = (int) round((float) (clone $query)->avg('latency_ms'));

        return [
            'total_requests' => $total,
            'success_rate' => $total > 0 ? round(($success / $total) * 100, 1) : 0.0,
            'total_input_tokens' => $input,
            'total_output_tokens' => $output,
            'total_tokens' => $input + $output,
            'avg_latency_ms' => $latency,
        ];
    }

    /**
     * Return usage aggregates grouped by provider platform and label.
     *
     * @return Collection<int, \stdClass>
     */
    public function byProvider(string $range = '7d'): Collection
    {
        return AiDevApiRequest::query()
            ->toBase()
            ->selectRaw('platform, provider_label, COUNT(*) as requests, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success_count, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, AVG(latency_ms) as avg_latency_ms', ['success'])
            ->where('created_at', '>=', $this->since($range))
            ->groupBy('platform', 'provider_label')
            ->orderByDesc('requests')
            ->get();
    }

    /**
     * Return usage aggregates grouped by model identifier.
     *
     * @return Collection<int, \stdClass>
     */
    public function byModel(string $range = '7d'): Collection
    {
        return AiDevApiRequest::query()
            ->toBase()
            ->selectRaw('platform, model_id, COUNT(*) as requests, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success_count, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, AVG(latency_ms) as avg_latency_ms', ['success'])
            ->where('created_at', '>=', $this->since($range))
            ->groupBy('platform', 'model_id')
            ->orderByDesc('requests')
            ->get();
    }

    /**
     * Return usage error aggregates grouped by error category.
     *
     * @return Collection<int, \stdClass>
     */
    public function errors(string $range = '7d'): Collection
    {
        return AiDevApiRequest::query()
            ->toBase()
            ->selectRaw('platform, model_id, error_category, COUNT(*) as count')
            ->where('status', 'error')
            ->where('created_at', '>=', $this->since($range))
            ->groupBy('platform', 'model_id', 'error_category')
            ->orderByDesc('count')
            ->get();
    }

    /**
     * Resolve the analytics lower-bound timestamp from the configured lookback period.
     */
    private function since(string $range): \DateTimeInterface
    {
        return match ($range) {
            '24h' => now()->subDay(),
            '30d' => now()->subDays(30),
            default => now()->subDays(7),
        };
    }
}
