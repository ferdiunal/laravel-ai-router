<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Models;

use Illuminate\Support\Carbon;

/**
 * Represents a curated or runtime-discovered model that can be used by the router.
 *
 * @property int $id
 * @property string $platform
 * @property string $model_id
 * @property string $display_name
 * @property int $intelligence_rank
 * @property int|null $speed_rank
 * @property int|null $rpm_limit
 * @property int|null $rpd_limit
 * @property int|null $tpm_limit
 * @property int|null $tpd_limit
 * @property string|null $budget_label
 * @property int|null $context_window
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AiDevApiModel extends AiDevApiBaseModel
{
    protected $table = 'ai_dev_api_models';

    protected $guarded = [];

    /**
     * Return Eloquent attribute cast definitions for this model.
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'rpm_limit' => 'int',
            'rpd_limit' => 'int',
            'tpm_limit' => 'int',
            'tpd_limit' => 'int',
            'context_window' => 'int',
            'intelligence_rank' => 'int',
            'speed_rank' => 'int',
        ];
    }
}
