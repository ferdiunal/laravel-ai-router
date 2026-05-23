<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Provides the shared package database connection for all Laravel AI Router Eloquent models.
 */
abstract class LaravelAiRouterBaseModel extends Model
{
    /**
     * Return the package database connection name used by all Laravel AI Router models.
     */
    public function getConnectionName(): ?string
    {
        return (string) (config('laravel-ai-router.database.connection') ?: 'laravel-ai-router');
    }
}
