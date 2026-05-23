<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Provides the shared package database connection for all AI Dev API Eloquent models.
 */
abstract class AiDevApiBaseModel extends Model
{
    /**
     * Return the package database connection name used by all AI Dev API models.
     */
    public function getConnectionName(): ?string
    {
        return (string) (config('ai-dev-api.database.connection') ?: 'ai-dev-api');
    }
}
