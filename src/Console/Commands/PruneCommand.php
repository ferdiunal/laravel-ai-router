<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Commands;

use Ferdiunal\LaravelAiRouter\Services\StoragePruner;
use Illuminate\Console\Command;

/**
 * Prunes old package-owned usage analytics and rate-window rows.
 */
final class PruneCommand extends Command
{
    protected $signature = 'laravel-ai-router:prune {--vacuum : Run SQLite VACUUM after pruning when supported.}';

    protected $description = 'Prune old Laravel AI Router usage and rate-window rows.';

    /**
     * Execute package storage pruning and optionally run SQLite VACUUM.
     */
    public function handle(StoragePruner $pruner): int
    {
        $result = $pruner->prune();

        $this->components->info("Pruned {$result['usage']} usage rows and {$result['rate_windows']} rate-window rows.");

        if ($this->option('vacuum')) {
            $this->components->info($pruner->vacuum() ? 'SQLite VACUUM completed.' : 'SQLite VACUUM skipped for this connection.');
        }

        return self::SUCCESS;
    }
}
