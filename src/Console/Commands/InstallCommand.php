<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Commands;

use Ferdiunal\LaravelAiRouter\Catalog\SeedModelCatalog;
use Ferdiunal\LaravelAiRouter\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\LaravelAiRouter\Console\Wizards\ProviderKeySetupWizard;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Services\SqliteOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;

/**
 * Runs the package installer that prepares internal storage, migrations, catalog data, SQLite optimization, and optional provider setup.
 */
final class InstallCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'laravel-ai-router:install
        {--with-provider : Launch the interactive provider key setup wizard after installation.}';

    protected $description = 'Prepare Laravel AI Router local storage and optionally add a provider key.';

    /**
     * Prepare package storage, run internal migrations, seed catalogs, optimize SQLite, and optionally launch setup.
     */
    public function handle(
        SeedModelCatalog $seedModelCatalog,
        SqliteOptimizer $sqliteOptimizer,
        ProviderKeySetupWizard $providerWizard,
    ): int {
        $connection = (string) (config('laravel-ai-router.database.connection') ?: 'laravel-ai-router');

        info('Preparing Laravel AI Router local storage.');
        $database = $this->ensureSqliteDatabaseFile($connection);
        if ($database !== null) {
            info('Local SQLite storage ready: '.$database);
        }

        $this->runInternalMigrations($connection);
        info('Internal database tables are ready.');

        if (Schema::connection($connection)->hasTable('laravel_ai_router_models')) {
            $seedModelCatalog->seed();
            info('Curated free model catalog seeded.');
        }

        $applied = $sqliteOptimizer->optimize($connection);
        info('SQLite optimizer checked'.($applied === [] ? ' (no-op).' : ': '.implode(', ', $applied)));

        if ($this->option('with-provider')) {
            if (! $this->shouldPrompt()) {
                info('Provider key setup skipped because this execution is non-interactive.');
            } else {
                $hasProviderKeys = LaravelAiRouterProviderKey::query()->exists();
                if (! $hasProviderKeys || $this->confirmPrompt('Add another provider key now?', false)) {
                    $providerWizard->run(true);
                }
            }
        }

        outro('Laravel AI Router install flow completed.');

        return self::SUCCESS;
    }

    /**
     * Create the package SQLite database file and parent directory when the dedicated connection targets SQLite storage.
     */
    private function ensureSqliteDatabaseFile(string $connection): ?string
    {
        if (config("database.connections.{$connection}.driver") !== 'sqlite') {
            return null;
        }

        $database = (string) config("database.connections.{$connection}.database", '');
        if ($database === '' || $database === ':memory:') {
            return null;
        }

        $directory = dirname($database);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (! file_exists($database)) {
            touch($database);
        }

        return $database;
    }

    /**
     * Execute package-owned internal migrations against the configured package connection.
     */
    private function runInternalMigrations(string $connection): void
    {
        Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => dirname(__DIR__, 3).'/database/migrations',
            '--realpath' => true,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
}
