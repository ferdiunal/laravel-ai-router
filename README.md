# AI Dev API

Laravel AI SDK için provider/key/model routing paketi. Amaç: birden fazla ücretsiz veya düşük maliyetli OpenAI-compatible provider anahtarını Laravel içinde yönetmek, desteklenen free modelleri provider + label bazında cachelemek, `auto` model routing yapmak ve kullanım istatistiklerini lokal veritabanında tutmak.

## Özellikler

- Laravel AI driver adı: `ai-dev-api`
- Varsayılan text model: `auto`
- Provider API key yönetimi: ekle, sil, listele, aktif/pasif yap
- API key değerleri encrypted saklanır, CLI çıktısında maskelenir
- Provider + label bazlı free model cache
- `AiDevApiProvider::models()` ile cachelenmiş model id erişimi
- Non-streaming ve streaming text gateway desteği
- Laravel AI structured output desteği (`StructuredTextResponse` / `StructuredAgentResponse`)
- OpenAI-compatible function tool-call loop desteği (non-stream)
- Laravel AI failover için retryable hata mapping'i (`RateLimitedException`, `InsufficientCreditsException`, `ProviderOverloadedException`)
- Request/usage analytics: provider, label, model, token, latency, error category
- SQLite için config kontrollü WAL/PRAGMA optimizasyonu
- Laravel Prompts tabanlı interaktif Artisan komutları

## Kurulum

```bash
composer require ferdiunal/ai-dev-api
php artisan ai-dev-api:install
```

Install komutu config/migration publish eder, migration çalıştırmayı sorar, model catalog seed eder ve SQLite kullanılıyorsa optimizer uygular.

Manuel publish istersen:

```bash
php artisan vendor:publish --tag=ai-dev-api-config
php artisan vendor:publish --tag=ai-dev-api-migrations
php artisan migrate
```

## Laravel AI config

`config/ai.php` içinde provider kaydı:

```php
'providers' => [
    'ai-dev-api' => [
        'driver' => 'ai-dev-api',
    ],
],

'default' => 'ai-dev-api',
```

Paket config varsayılanları `config/ai-dev-api.php` içindedir:

```php
'driver' => 'ai-dev-api',

'models' => [
    'text' => [
        'default' => 'auto',
    ],
],
```

## Provider key yönetimi

Komutlar Laravel Prompts kullanır; interaktif olarak provider, label ve API key alır.

```bash
php artisan ai-dev-api:provider:add
php artisan ai-dev-api:provider:list
php artisan ai-dev-api:provider:models
php artisan ai-dev-api:provider:enable
php artisan ai-dev-api:provider:disable
php artisan ai-dev-api:provider:remove
```

Provider key kimliği `provider + label` ile ayrılır. Örneğin aynı `openrouter` provider için `Primary`, `Backup`, `Team` gibi farklı label'lar kullanılabilir.

## Model cache

Bir provider key eklendiğinde veya `provider:models` komutuyla refresh edildiğinde provider'ın desteklediği free modeller cachelenir.

```bash
php artisan ai-dev-api:provider:models
```

Kod içinden erişim:

```php
use Ferdiunal\AiDevApi\AiDevApiProvider;
use Laravel\Ai\AiManager;

$provider = app(AiManager::class)->textProvider('ai-dev-api');

assert($provider instanceof AiDevApiProvider);

$modelIds = $provider->models('openrouter', 'Primary');
// ['auto', 'qwen/qwen3-coder:free', ...]
```

## Prompt kullanımı

Laravel AI agent/prompt akışında model `auto` bırakıldığında router kullanılabilir provider key ve model seçer.

```php
$response = ai()
    ->using('ai-dev-api', 'auto')
    ->prompt('Kısa bir özet çıkar.')
    ->asText();
```

Streaming path de desteklenir; OpenAI-compatible SSE chunk'ları Laravel AI stream eventlerine çevrilir.

Agent attribute kullanımı:

```php
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Provider('ai-dev-api')]
#[Model('auto')]
final class SupportAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Kısa, net ve Türkçe cevap ver.';
    }
}
```

Structured output için Laravel AI `HasStructuredOutput` contract'ı desteklenir. Paket upstream'e JSON mode gönderir, dönen JSON metnini SDK'nın `StructuredTextResponse` / `StructuredAgentResponse` tiplerine çevirir.

Tool kullanımı non-stream akışta desteklenir: provider `tool_calls` döndürürse paket tool'u çalıştırır, sonucu `tool` mesajı olarak upstream'e geri gönderir ve final cevabı döndürür. Streaming tool-call akışı henüz desteklenmediği için provider stream açılmadan açık `LogicException` fırlatılır.

Failover örneği:

```php
$response = SupportAgent::make()->prompt(
    'Sipariş durumunu özetle.',
    provider: [
        'ai-dev-api' => 'auto',
        'openai' => 'gpt-4o-mini',
    ],
    timeout: 20,
);
```

`429/rate limit`, yetersiz kredi/kota ve geçici provider overload/timeout hataları Laravel AI failover exception tiplerine map edilir. Böylece provider array kullanan agent'larda SDK bir sonraki provider/model çiftine geçebilir.

## Laravel AI SDK destek matrisi

| Capability | Durum | Not |
| --- | --- | --- |
| Text generation | Destekleniyor | `TextResponse`, usage, meta, assistant messages ve step bilgisi döner. |
| Text streaming | Destekleniyor | `StreamStart`, `TextStart`, `TextDelta`, `TextEnd`, `StreamEnd`; SSE line/event byte limitleri var. |
| Structured output | Destekleniyor | JSON mode + SDK `StructuredTextResponse` / `StructuredAgentResponse`. |
| Function tools | Non-stream destekleniyor | OpenAI-compatible `tools`/`tool_calls` loop'u, tool callbacks ve step aggregation desteklenir. |
| Streaming tools | Bilerek kapalı | Provider stream açılmadan `LogicException`; kısmi/yanlış tool stream yok. |
| Failover | Destekleniyor | Rate limit, insufficient credits/quota, timeout/overload SDK failover exception tiplerine map edilir. |
| Image/audio/transcription/embeddings/reranking/files/stores | Desteklenmiyor | Provider yalnızca `TextProvider` advertise eder; gateway contract gereği unsupported methodlar açık hata verir. |

## Kullanım istatistikleri

```bash
php artisan ai-dev-api:usage
```

Tutulan alanlar:

- provider platform
- provider label
- model id
- success/error status
- input/output/total tokens
- latency
- error type/category/message

## SQLite optimizasyonu

SQLite driver kullanıldığında ve config açıksa paket şu PRAGMA ayarlarını uygular:

- `journal_mode = WAL` (`:memory:` hariç)
- `foreign_keys = ON`
- `busy_timeout`
- `synchronous`
- `temp_store = MEMORY`
- `cache_size`

Config:

```php
'database' => [
    'sqlite' => [
        'optimize' => true,
        'journal_mode' => 'WAL',
        'synchronous' => 'NORMAL',
        'busy_timeout_ms' => 5000,
        'cache_size_kb' => 20000,
    ],
],
```

## Test ve kalite kapıları

```bash
composer format:check
composer analyse
composer test
composer ci
```

Mevcut statik analiz seviyesi: PHPStan/Larastan level 6.

## Güvenlik notları

- API key'ler encrypted saklanır.
- CLI listelerinde raw key gösterilmez; masked değer gösterilir.
- Usage logging hata mesajlarında bearer token redaction uygular.

## Changelog

Değişiklikler için [CHANGELOG](CHANGELOG.md) dosyasına bak.

## License

MIT. Detay için [LICENSE.md](LICENSE.md).
