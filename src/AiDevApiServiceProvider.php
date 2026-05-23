<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi;

use Ferdiunal\AiDevApi\Console\Commands\InstallCommand;
use Ferdiunal\AiDevApi\Console\Commands\ProviderAddCommand;
use Ferdiunal\AiDevApi\Console\Commands\ProviderDefinitionAddCommand;
use Ferdiunal\AiDevApi\Console\Commands\ProviderDefinitionDisableCommand;
use Ferdiunal\AiDevApi\Console\Commands\ProviderDefinitionEnableCommand;
use Ferdiunal\AiDevApi\Console\Commands\ProviderDefinitionListCommand;
use Ferdiunal\AiDevApi\Console\Commands\ProviderDefinitionRemoveCommand;
use Ferdiunal\AiDevApi\Console\Commands\ProviderDisableCommand;
use Ferdiunal\AiDevApi\Console\Commands\ProviderEnableCommand;
use Ferdiunal\AiDevApi\Console\Commands\ProviderListCommand;
use Ferdiunal\AiDevApi\Console\Commands\ProviderModelsCommand;
use Ferdiunal\AiDevApi\Console\Commands\ProviderRemoveCommand;
use Ferdiunal\AiDevApi\Console\Commands\UsageCommand;
use Ferdiunal\AiDevApi\Gateway\AiDevApiTextGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;

final class AiDevApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-dev-api.php', 'ai-dev-api');

        $this->app->singleton(AiDevApiTextGateway::class);

        $this->app->afterResolving(AiManager::class, function (AiManager $manager): void {
            $manager->extend('ai-dev-api', function ($app, array $config): AiDevApiProvider {
                return new AiDevApiProvider(
                    gateway: $app->make(AiDevApiTextGateway::class),
                    config: ['driver' => 'ai-dev-api', ...$config, 'name' => $config['name'] ?? 'ai-dev-api'],
                    events: $app->make(Dispatcher::class),
                );
            });
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ProviderAddCommand::class,
                ProviderDefinitionAddCommand::class,
                ProviderDefinitionDisableCommand::class,
                ProviderDefinitionEnableCommand::class,
                ProviderDefinitionListCommand::class,
                ProviderDefinitionRemoveCommand::class,
                ProviderDisableCommand::class,
                ProviderEnableCommand::class,
                ProviderListCommand::class,
                ProviderModelsCommand::class,
                ProviderRemoveCommand::class,
                UsageCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/ai-dev-api.php' => config_path('ai-dev-api.php'),
            ], ['ai-dev-api', 'ai-dev-api-config']);

            $this->publishes($this->migrationPublishes(), ['ai-dev-api', 'ai-dev-api-migrations']);
        }
    }

    /** @return array<string, string> */
    private function migrationPublishes(): array
    {
        $publishes = [];

        foreach (glob(__DIR__.'/../database/migrations/*.php.stub') ?: [] as $source) {
            $publishes[$source] = database_path('migrations/'.basename($source, '.stub'));
        }

        return $publishes;
    }
}
