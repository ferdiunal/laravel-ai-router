<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Catalog\SeedModelCatalog;
use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Console\Wizards\ProviderKeySetupWizard;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Ferdiunal\AiDevApi\Services\SqliteOptimizer;
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

    protected $signature = 'ai-dev-api:install';

    protected $description = 'Prepare AI Dev API local storage and optionally add a provider key.';

    /**
     * Prepare package storage, run internal migrations, seed catalogs, optimize SQLite, and optionally launch setup.
     */
    public function handle(
        SeedModelCatalog $seedModelCatalog,
        SqliteOptimizer $sqliteOptimizer,
        ProviderKeySetupWizard $providerWizard,
    ): int {
        $connection = (string) (config('ai-dev-api.database.connection') ?: 'ai-dev-api');

        info('Preparing AI Dev API local storage.');
        $database = $this->ensureSqliteDatabaseFile($connection);
        if ($database !== null) {
            info('Local SQLite storage ready: '.$database);
        }

        $this->runInternalMigrations($connection);
        info('Internal database tables are ready.');

        if (Schema::connection($connection)->hasTable('ai_dev_api_models')) {
            $seedModelCatalog->seed();
            info('Curated free model catalog seeded.');
        }

        $applied = $sqliteOptimizer->optimize($connection);
        info('SQLite optimizer checked'.($applied === [] ? ' (no-op).' : ': '.implode(', ', $applied)));

        if ($this->shouldPrompt()) {
            $hasProviderKeys = AiDevApiProviderKey::query()->exists();
            if (! $hasProviderKeys || $this->confirmPrompt('Add another provider key now?', false)) {
                $providerWizard->run(true);
            }
        }

        outro('AI Dev API install flow completed.');

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
        ]);
    }
}
