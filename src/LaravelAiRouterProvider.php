<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter;

use Ferdiunal\LaravelAiRouter\Services\ModelPreferenceManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelCacheService;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;

/**
 * Registers Laravel AI Router as a Laravel AI text provider facade backed by the package text gateway and model cache.
 */
final class LaravelAiRouterProvider extends Provider implements TextProvider
{
    use GeneratesText;
    use HasTextGateway;
    use StreamsText;

    /**
     * Return the configured default text model after applying the persisted package preference fallback.
     */
    public function defaultTextModel(): string
    {
        $fallback = (string) data_get($this->config, 'models.text.default', config('laravel-ai-router.models.text.default', 'auto'));

        return app(ModelPreferenceManager::class)->defaultTextModel($fallback);
    }

    /**
     * Return the first enabled routable text model for Laravel AI's cheapest-model contract.
     */
    public function cheapestTextModel(): string
    {
        return app(ProviderModelCacheService::class)->firstAvailableModelId() ?? $this->defaultTextModel();
    }

    /**
     * Return the preferred enabled routable text model for Laravel AI's smartest-model contract.
     */
    public function smartestTextModel(): string
    {
        return app(ProviderModelCacheService::class)->smartestAvailableModelId() ?? $this->defaultTextModel();
    }

    /**
     * Return cached available model IDs for this package, optionally scoped by provider + label.
     *
     * @return array<int, string>
     */
    public function models(?string $provider = null, ?string $label = null, bool $includeAuto = true): array
    {
        return app(ProviderModelCacheService::class)->modelIds($provider, $label, $includeAuto);
    }
}
