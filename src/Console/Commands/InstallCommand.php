<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\AiDevApiServiceProvider;
use Ferdiunal\AiDevApi\Catalog\SeedModelCatalog;
use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Services\SqliteOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;

final class InstallCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:install';

    protected $description = 'Interactively publish AI Dev API config/migrations and seed the model catalog.';

    public function handle(SeedModelCatalog $seedModelCatalog, SqliteOptimizer $sqliteOptimizer): int
    {
        if ($this->confirmPrompt('Publish AI Dev API config?', true)) {
            $this->callSilent('vendor:publish', [
                '--provider' => AiDevApiServiceProvider::class,
                '--tag' => 'ai-dev-api-config',
            ]);
            info('Config published.');
        }

        if ($this->confirmPrompt('Publish AI Dev API migrations?', true)) {
            $this->callSilent('vendor:publish', [
                '--provider' => AiDevApiServiceProvider::class,
                '--tag' => 'ai-dev-api-migrations',
            ]);
            info('Migrations published.');
        }

        if ($this->confirmPrompt('Run migrations now?', false)) {
            Artisan::call('migrate', ['--force' => true]);
            info('Migrations executed.');
        }

        if ($this->confirmPrompt('Seed curated free model catalog?', true) && Schema::hasTable('ai_dev_api_models')) {
            $seedModelCatalog->seed();
            info('Curated model catalog seeded.');
        } elseif (! Schema::hasTable('ai_dev_api_models')) {
            info('Run migrations before seeding the curated model catalog.');
        }

        if ($this->confirmPrompt('Apply SQLite optimizations when applicable?', true)) {
            $applied = $sqliteOptimizer->optimize();
            info('SQLite optimizer checked'.($applied === [] ? ' (no-op).' : ': '.implode(', ', $applied)));
        }

        outro('AI Dev API install flow completed.');

        return self::SUCCESS;
    }
}
