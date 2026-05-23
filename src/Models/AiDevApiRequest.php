<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $request_id
 * @property string $platform
 * @property string|null $provider_label
 * @property string $model_id
 * @property int|null $provider_key_id
 * @property string $status
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $total_tokens
 * @property int $latency_ms
 * @property string|null $error_type
 * @property string|null $error_code
 * @property string|null $error_category
 * @property string|null $error_message
 * @property int $attempt
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 */
final class AiDevApiRequest extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ai_dev_api_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider_key_id' => 'int',
            'input_tokens' => 'int',
            'output_tokens' => 'int',
            'total_tokens' => 'int',
            'latency_ms' => 'int',
            'attempt' => 'int',
            'metadata' => 'array',
        ];
    }
}
