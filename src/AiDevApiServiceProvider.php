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

/**
 * Bootstraps the package configuration, Artisan commands, Laravel AI driver extension, and dedicated package database connection.
 */
final class AiDevApiServiceProvider extends ServiceProvider
{
    /**
     * Register package configuration, services, and Laravel AI provider bindings in the application container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-dev-api.php', 'ai-dev-api');
        $this->normalizeDatabaseConfig();
        $this->registerAiDevApiDatabaseConnection();

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

    /**
     * Publish package configuration and register console-only package commands during application boot.
     */
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
        }
    }

    /**
     * Normalize package database configuration before registering the dedicated package connection.
     */
    private function normalizeDatabaseConfig(): void
    {
        if (! config('ai-dev-api.database.connection')) {
            config()->set('ai-dev-api.database.connection', 'ai-dev-api');
        }

        if (! config('ai-dev-api.database.sqlite.database')) {
            config()->set('ai-dev-api.database.sqlite.database', database_path('ai-dev-api.sqlite'));
        }
    }

    /**
     * Register the dedicated package database connection without overriding host application connections.
     */
    private function registerAiDevApiDatabaseConnection(): void
    {
        $connection = (string) (config('ai-dev-api.database.connection') ?: 'ai-dev-api');

        if ($connection !== 'ai-dev-api' || config('database.connections.ai-dev-api') !== null) {
            return;
        }

        config()->set('database.connections.ai-dev-api', [
            'driver' => 'sqlite',
            'database' => config('ai-dev-api.database.sqlite.database', database_path('ai-dev-api.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
