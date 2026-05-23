<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Services;

use Ferdiunal\AiDevApi\Models\AiDevApiSetting;
use Illuminate\Database\QueryException;
use Throwable;

final class ModelPreferenceManager
{
    public const DEFAULT_TEXT_MODEL_KEY = 'default_text_model_id';

    public function defaultTextModel(string $fallback = 'auto'): string
    {
        try {
            $setting = AiDevApiSetting::query()->find(self::DEFAULT_TEXT_MODEL_KEY);
        } catch (QueryException) {
            return $fallback;
        } catch (Throwable) {
            return $fallback;
        }

        $modelId = data_get($setting?->value, 'model_id');

        return is_string($modelId) && trim($modelId) !== '' ? $modelId : $fallback;
    }

    public function setDefaultTextModel(string $modelId): void
    {
        $modelId = trim($modelId) !== '' ? trim($modelId) : 'auto';

        AiDevApiSetting::query()->updateOrCreate(
            ['key' => self::DEFAULT_TEXT_MODEL_KEY],
            ['value' => ['model_id' => $modelId]],
        );
    }

    public function clearDefaultTextModel(): void
    {
        AiDevApiSetting::query()->whereKey(self::DEFAULT_TEXT_MODEL_KEY)->delete();
    }
}
