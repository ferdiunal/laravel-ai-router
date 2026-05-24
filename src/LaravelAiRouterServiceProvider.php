<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter;

use Ferdiunal\LaravelAiRouter\Console\Commands\InstallCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderAddCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderDefinitionAddCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderDefinitionDisableCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderDefinitionEnableCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderDefinitionListCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderDefinitionRemoveCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderDisableCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderEnableCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderListCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderModelsCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderRemoveCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\ProviderSyncCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\PruneCommand;
use Ferdiunal\LaravelAiRouter\Console\Commands\UsageCommand;
use Ferdiunal\LaravelAiRouter\Gateway\LaravelAiRouterTextGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;

/**
 * Bootstraps the package configuration, Artisan commands, Laravel AI driver extension, and dedicated package database connection.
 */
final class LaravelAiRouterServiceProvider extends ServiceProvider
{
    /**
     * Register package configuration, services, and Laravel AI provider bindings in the application container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-ai-router.php', 'laravel-ai-router');
        $this->normalizeDatabaseConfig();
        $this->registerLaravelAiRouterDatabaseConnection();

        $this->app->singleton(LaravelAiRouterTextGateway::class);

        $this->app->afterResolving(AiManager::class, function (AiManager $manager): void {
            $manager->extend('laravel-ai-router', function ($app, array $config): LaravelAiRouterProvider {
                return new LaravelAiRouterProvider(
                    gateway: $app->make(LaravelAiRouterTextGateway::class),
                    config: ['driver' => 'laravel-ai-router', ...$config, 'name' => $config['name'] ?? 'laravel-ai-router'],
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
                ProviderSyncCommand::class,
                PruneCommand::class,
                UsageCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/laravel-ai-router.php' => config_path('laravel-ai-router.php'),
            ], ['laravel-ai-router', 'laravel-ai-router-config']);
        }
    }

    /**
     * Normalize package database configuration before registering the dedicated package connection.
     */
    private function normalizeDatabaseConfig(): void
    {
        if (! config('laravel-ai-router.database.connection')) {
            config()->set('laravel-ai-router.database.connection', 'laravel-ai-router');
        }

        if (! config('laravel-ai-router.database.sqlite.database')) {
            config()->set('laravel-ai-router.database.sqlite.database', database_path('laravel-ai-router.sqlite'));
        }
    }

    /**
     * Register the dedicated package database connection without overriding host application connections.
     */
    private function registerLaravelAiRouterDatabaseConnection(): void
    {
        $connection = (string) (config('laravel-ai-router.database.connection') ?: 'laravel-ai-router');

        if ($connection !== 'laravel-ai-router' || config('database.connections.laravel-ai-router') !== null) {
            return;
        }

        config()->set('database.connections.laravel-ai-router', [
            'driver' => 'sqlite',
            'database' => config('laravel-ai-router.database.sqlite.database', database_path('laravel-ai-router.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
