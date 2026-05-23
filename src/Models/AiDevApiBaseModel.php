<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Models;

use Illuminate\Database\Eloquent\Model;

abstract class AiDevApiBaseModel extends Model
{
    public function getConnectionName(): ?string
    {
        return (string) (config('ai-dev-api.database.connection') ?: 'ai-dev-api');
    }
}
