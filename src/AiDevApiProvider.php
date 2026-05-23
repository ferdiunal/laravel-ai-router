<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi;

use Ferdiunal\AiDevApi\Services\ModelPreferenceManager;
use Ferdiunal\AiDevApi\Services\ProviderModelCacheService;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;

final class AiDevApiProvider extends Provider implements TextProvider
{
    use GeneratesText;
    use HasTextGateway;
    use StreamsText;

    public function defaultTextModel(): string
    {
        $fallback = (string) data_get($this->config, 'models.text.default', config('ai-dev-api.models.text.default', 'auto'));

        return app(ModelPreferenceManager::class)->defaultTextModel($fallback);
    }

    public function cheapestTextModel(): string
    {
        return app(ProviderModelCacheService::class)->firstAvailableModelId() ?? $this->defaultTextModel();
    }

    public function smartestTextModel(): string
    {
        return app(ProviderModelCacheService::class)->smartestAvailableModelId() ?? $this->defaultTextModel();
    }

    /**
     * Return cached free model IDs for this package, optionally scoped by provider + label.
     *
     * @return array<int, string>
     */
    public function models(?string $provider = null, ?string $label = null, bool $includeAuto = true): array
    {
        return app(ProviderModelCacheService::class)->modelIds($provider, $label, $includeAuto);
    }
}
