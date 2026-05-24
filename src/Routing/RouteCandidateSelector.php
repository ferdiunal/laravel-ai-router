<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Routing;

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Orders enabled fallback candidates according to the configured auto-routing strategy.
 */
final class RouteCandidateSelector
{
    /**
     * Return fallback candidates in the order the router should attempt them.
     *
     * @param  Collection<int, LaravelAiRouterFallback>  $fallbacks
     * @return Collection<int, LaravelAiRouterFallback>
     */
    public function orderedFallbacks(Collection $fallbacks): Collection
    {
        $ordered = $fallbacks
            ->sort(fn (LaravelAiRouterFallback $left, LaravelAiRouterFallback $right): int => [
                $this->effectivePriority($left),
                (int) $left->getKey(),
            ] <=> [
                $this->effectivePriority($right),
                (int) $right->getKey(),
            ])
            ->values();

        if ($this->strategy() !== 'balanced_random' || $ordered->count() <= 1) {
            return $ordered;
        }

        $bestPriority = $this->effectivePriority($ordered->first());
        $poolSize = $this->randomPoolSize();
        $priorityWindow = $this->randomPriorityWindow();

        $pool = $ordered
            ->filter(fn (LaravelAiRouterFallback $fallback, int $index): bool => $index < $poolSize
                && $this->effectivePriority($fallback) <= $bestPriority + $priorityWindow)
            ->values();

        if ($pool->count() <= 1) {
            return $ordered;
        }

        $poolIds = $pool
            ->map(fn (LaravelAiRouterFallback $fallback): int => (int) $fallback->getKey())
            ->all();

        $shuffledPool = collect($this->randomizer()->shuffleArray($pool->all()));
        $remaining = $ordered
            ->reject(fn (LaravelAiRouterFallback $fallback): bool => in_array((int) $fallback->getKey(), $poolIds, true))
            ->values();

        return $shuffledPool->concat($remaining)->values();
    }

    /**
     * Effective fallback order includes persisted dynamic penalty state.
     */
    private function effectivePriority(LaravelAiRouterFallback $fallback): int
    {
        return (int) $fallback->priority + (int) $fallback->penalty;
    }

    /**
     * Resolve and sanitize the configured auto-routing strategy.
     */
    private function strategy(): string
    {
        $strategy = config('laravel-ai-router.routing.auto_strategy', 'priority');

        return $strategy === 'balanced_random' ? 'balanced_random' : 'priority';
    }

    /**
     * Resolve the maximum number of top candidates eligible for randomization.
     */
    private function randomPoolSize(): int
    {
        $value = config('laravel-ai-router.routing.random_pool_size', 5);
        $poolSize = is_numeric($value) ? (int) $value : 5;

        return max(1, min(50, $poolSize));
    }

    /**
     * Resolve the effective-priority distance from the best candidate allowed into the random pool.
     */
    private function randomPriorityWindow(): int
    {
        $value = config('laravel-ai-router.routing.random_priority_window', 3);
        $priorityWindow = is_numeric($value) ? (int) $value : 3;

        return max(0, min(100, $priorityWindow));
    }

    /**
     * Use a deterministic randomizer only when tests explicitly provide a seed.
     */
    private function randomizer(): Randomizer
    {
        $seed = config('laravel-ai-router.routing.random_seed');

        if (is_int($seed) || (is_string($seed) && is_numeric($seed))) {
            return new Randomizer(new Mt19937((int) $seed));
        }

        return new Randomizer;
    }
}
