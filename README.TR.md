# AI Dev API

Türkçe | [English](README.md)

AI Dev API, Laravel AI SDK için geliştirilmiş bir text provider paketidir. Paket, tek bir Laravel AI provider adı (`ai-dev-api`) üzerinden birden fazla OpenAI-compatible provider anahtarını, provider + label bazlı free model cache kayıtlarını, fallback model sıralamasını, yerel rate-limit pencerelerini ve usage analytics verisini yönetir.

Paket kendi operasyonel durumunu varsayılan olarak ayrılmış bir paket database connection içinde saklar. Varsayılan storage hedefi `database/ai-dev-api.sqlite` dosyasıdır. Böylece provider key kayıtları, model cache satırları, fallback routing satırları, rate-limit sayaçları, kullanım kayıtları, runtime custom provider tanımları ve package setting kayıtları host uygulamanın ana tablolarından ayrılır.

## Özellikler

- Laravel AI driver adı: `ai-dev-api`.
- Varsayılan text model: `auto`.
- Provider API key yönetimi: ekle, listele, aktif et, pasif et, sil.
- Runtime custom OpenAI-compatible provider yönetimi: ekle, listele, aktif et, pasif et, sil.
- Laravel encryption ile encrypted API-key saklama.
- Maskelenmiş CLI çıktısı; raw provider key değerleri yazdırılmaz.
- Provider + label scope'lu free model cache.
- `AiDevApiProvider::models()` ile cached model ID erişimi.
- Laravel AI `TextProvider` üzerinden non-streaming text generation.
- Laravel AI stream eventleri üzerinden streaming text generation.
- Laravel AI structured response tipleriyle structured output desteği.
- Non-stream OpenAI-compatible function tool-call loop desteği.
- Retryable provider hataları için Laravel AI failover exception mapping.
- Provider, label, model, status, token sayıları, latency ve error category bazlı yerel usage analytics.
- WAL, foreign keys, busy timeout, synchronous mode, temp store ve cache-size kontrollü bounded SQLite optimizasyonu.
- Laravel Prompts tabanlı interaktif Artisan komutları.

## Gereksinimler

- PHP `^8.4`
- Laravel components `^13.0`
- `laravel/ai ^0.7`
- `laravel/prompts ^0.3.6`

Paket bir Laravel package olarak çalışır ve `composer.json` içindeki service provider tanımı üzerinden otomatik keşfedilir.

## Kurulum

```bash
composer require ferdiunal/ai-dev-api
php artisan ai-dev-api:install
```

Install komutu paket kurulumunu şu sırayla yapar:

1. Paket connection SQLite storage hedefliyorsa configured SQLite dosyasını oluşturur.
2. Package-owned internal migration dosyalarını configured package connection üzerinde çalıştırır.
3. Curated model catalog ve fallback ordering satırlarını seed eder.
4. Etkinse ve bağlantı uygunsa güvenli SQLite PRAGMA optimizasyonlarını uygular.
5. Interaktif console ortamında provider-key setup wizard akışını başlatır.

Varsayılan akışta host uygulamanın `database/migrations` dizinine migration publish etmek gerekmez. Paket migration dosyaları internal package migration olarak kalır ve `ai-dev-api:install` tarafından package connection üzerinde çalıştırılır.

## Config

Sadece varsayılanları değiştirmek istediğinde package config publish et:

```bash
php artisan vendor:publish --tag=ai-dev-api-config
```

Provider kaydını `config/ai.php` içine ekle:

```php
'providers' => [
    'ai-dev-api' => [
        'driver' => 'ai-dev-api',
    ],
],

'default' => 'ai-dev-api',
```

Package config içinde default text model `auto` olarak kalır:

```php
// config/ai-dev-api.php
return [
    'driver' => env('AI_DEV_API_DRIVER', 'ai-dev-api'),

    'models' => [
        'text' => [
            'default' => env('AI_DEV_API_DEFAULT_MODEL', 'auto'),
        ],
        'cache_ttl_minutes' => env('AI_DEV_API_MODELS_CACHE_TTL', 1440),
    ],
];
```

### Package database connection

Varsayılan package connection adı `ai-dev-api` değeridir. Bu connection adı kullanıldığında service provider, path override edilmediyse `database/ai-dev-api.sqlite` dosyasını kullanan dedicated SQLite connection register eder.

```env
# Dedicated package SQLite path. Varsayılan ai-dev-api connection bu dosyayı kullanır.
AI_DEV_API_SQLITE_DATABASE=/absolute/path/ai-dev-api.sqlite

# Opsiyonel: package storage'ı mevcut bir host application connection'a yönlendir.
# config/database.php içinde tanımlı mysql, pgsql veya sqlite gibi bir connection adı kullan.
AI_DEV_API_DB_CONNECTION=mysql
```

`AI_DEV_API_DB_CONNECTION` değerini sadece package-owned state'in mevcut bir host connection içinde tutulmasını bilerek istediğinde kullan. `ai-dev-api` değeri, paketin varsayılan dedicated SQLite connection'ını ifade eder.

## Provider Key Yönetimi

Provider key kayıtları `provider + label` ikilisiyle ayrılır. Böylece aynı provider için birden fazla key yönetilebilir; örneğin `openrouter / Primary`, `openrouter / Backup` veya `groq / Team`.

```bash
php artisan ai-dev-api:provider:add
php artisan ai-dev-api:provider:list
php artisan ai-dev-api:provider:models
php artisan ai-dev-api:provider:enable
php artisan ai-dev-api:provider:disable
php artisan ai-dev-api:provider:remove
```

`ai-dev-api:provider:add` Laravel Prompts ile şu girdileri alır:

1. Provider platform.
2. API key.
3. Provider-key label.
4. Opsiyonel model-cache refresh.
5. Cached free modeller içinden opsiyonel default model seçimi.

Raw API key persistence öncesi encrypt edilir ve command output içinde hiçbir zaman gösterilmez. Listeleme ve prompt ekranlarında sadece masked credential kullanılır.

## Runtime Custom OpenAI-compatible Provider'lar

Runtime custom provider desteği, package code değiştirmeden OpenAI-compatible gateway, proxy veya provider eklemeyi sağlar.

```bash
php artisan ai-dev-api:provider-definition:add
php artisan ai-dev-api:provider-definition:list
php artisan ai-dev-api:provider-definition:enable
php artisan ai-dev-api:provider-definition:disable
php artisan ai-dev-api:provider-definition:remove

php artisan ai-dev-api:provider:add
php artisan ai-dev-api:provider:models
```

Runtime provider definition alanları:

- Provider slug, örnek: `my-openai-proxy`.
- Görünen ad.
- OpenAI-compatible base URL, örnek: `https://api.example.com/v1`.
- Opsiyonel metadata headers JSON, örnek: `{"X-Title":"AI Dev API"}`.
- Timeout değeri, milliseconds cinsinden.
- Opsiyonel anonymous placeholder-key desteği.

Persistence ve request dispatch öncesinde güvenlik kısıtları uygulanır:

- Base URL public `https://` olmak zorundadır.
- Credentials, query string, fragment, localhost, local/test/internal hostnames, private IP ve reserved IP değerleri reddedilir.
- DNS resolution sadece public address döndürmelidir.
- Runtime provider validation sırasında redirect takip edilmez.
- Authentication taşıyabilecek header adları reddedilir; örnekler: `Authorization`, `Proxy-Authorization`, `X-Api-Key` ve token/secret/password benzeri header adları.
- Extra headers sadece metadata/proxy header'ları içindir; provider credentials provider key kayıtları üzerinden saklanmalıdır.

Static custom provider tanımı config üzerinden de yapılabilir:

```php
// config/ai-dev-api.php
'providers' => [
    'custom' => [
        'my-openai-proxy' => [
            'name' => 'My OpenAI Proxy',
            'base_url' => 'https://api.example.com/v1',
            'headers' => [
                'X-Title' => 'AI Dev API',
            ],
            'timeout_ms' => 30000,
            'requires_placeholder_key' => false,
        ],
    ],
],
```

Custom provider `/models` endpointinden free model ID döndürürse AI Dev API bu model ID'leri provider + label bazında cacheleyebilir ve routing içinde kullanılabilmeleri için runtime model/fallback satırlarını oluşturabilir.

## Model Cache ve Default Model Tercihi

Provider model cache kayıtları provider key scope'undadır. Bir cache satırı provider platform, provider label, model ID, display name, context window, rate limits, token limits, free-tier bilgisi, tool support bilgisi, source ve refresh timestamp alanlarını saklar.

Cache refresh ve listeleme için:

```bash
php artisan ai-dev-api:provider:models
```

Bu komut seçilen key için cache refresh yapabilir, cached free modelleri listeleyebilir ve searchable prompt ile default model seçtirebilir. Model listeleme ve default seçim akışı yalnızca şu kriterleri sağlayan kayıtları gösterir:

- Provider platform için routable adapter vardır.
- Provider key enabled durumdadır.
- Provider key status değeri `invalid` değildir.
- Provider key model cache süresi dolmamıştır.
- Cache row provider key ID, platform ve label ile eşleşir.
- Cache row enabled durumdadır ve free olarak işaretlenmiştir.

Package config default değeri `auto` olarak kalır. Kullanıcı CLI üzerinden default model seçtiğinde seçim package settings tablosuna yazılır ve runtime sırasında `AiDevApiProvider::defaultTextModel()` tarafından okunur. Bu işlem config dosyalarını değiştirmez.

Kod içinden model erişimi:

```php
use Ferdiunal\AiDevApi\AiDevApiProvider;
use Laravel\Ai\AiManager;

$provider = app(AiManager::class)->textProvider('ai-dev-api');

assert($provider instanceof AiDevApiProvider);

$modelIds = $provider->models('openrouter', 'Primary');
// ['auto', 'qwen/qwen3-coder:free', ...]
```

## Prompt Kullanımı

`auto` model değeri, request'in uygun provider key ve cached free modele route edilmesini sağlar:

```php
$response = ai()
    ->using('ai-dev-api', 'auto')
    ->prompt('Bu destek kaydını üç maddeyle özetle.')
    ->asText();
```

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
        return 'Kısa cevap ver ve operasyonel olarak önemli detayları ekle.';
    }
}
```

Laravel AI provider array ile failover kullanımı:

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

Retryable rate-limit, geçici overload, timeout ve insufficient-credit durumları mümkün olduğunda Laravel AI failover-compatible exception tiplerine map edilir.

## Laravel AI SDK Capability Matrix

| Capability | Durum | Not |
| --- | --- | --- |
| Text generation | Desteklenir | Usage ve metadata dahil Laravel AI `TextResponse` veya `StructuredTextResponse` döndürür. |
| Text streaming | Desteklenir | OpenAI-compatible SSE chunk'larını Laravel AI `StreamStart`, `TextStart`, `TextDelta`, `TextEnd` ve `StreamEnd` eventlerine çevirir. |
| Structured output | Desteklenir | JSON-mode benzeri seçenekleri gönderir ve geçerli JSON içeriğini Laravel AI structured response tiplerine map eder. |
| Function tools | Non-stream desteklenir | OpenAI-compatible `tools` / `tool_calls` loop'unu çalıştırır ve tool result mesajlarını provider'a geri gönderir. |
| Streaming tools | Desteklenmez | Upstream stream açılmadan açık bir `LogicException` ile fail eder. |
| Failover | Desteklenir | Rate limit, insufficient credit/quota, timeout ve overload hatalarını Laravel AI failover exception tiplerine map eder. |
| Images, audio, transcription, embeddings, reranking, files, stores | Desteklenmez | Paket yalnızca text provider contract advertise eder ve unsupported methodlar için açık capability hatası fırlatır. |

## Usage Analytics

Usage analytics çıktısı için:

```bash
php artisan ai-dev-api:usage
```

Tutulan alanlar:

- Provider platform.
- Provider label.
- Model ID.
- Success veya error status.
- Input tokens.
- Output tokens.
- Total tokens.
- Milliseconds cinsinden latency.
- Error type.
- Error category.
- Redacted error message.
- Request timestamp değerleri.

Usage satırları package-owned storage içinde tutulur. Bu veriler provider reliability, model dağılımı, latency, token volume ve error trendlerini incelemek için kullanılabilir.

## SQLite Optimizasyonu ve Package Storage

Package connection SQLite kullandığında ve optimization açık olduğunda paket bounded PRAGMA ayarlarını uygular:

- `journal_mode = WAL`, in-memory database hariç.
- `foreign_keys = ON`.
- Config değerinden gelen ve safe bounds içine clamp edilen `busy_timeout`.
- Allowed enum-like set üzerinden gelen `synchronous`.
- Etkin olduğunda `temp_store = MEMORY`.
- Config değerinden gelen ve safe bounds içine clamp edilen `cache_size`.

İlgili config şekli:

```php
'database' => [
    'connection' => env('AI_DEV_API_DB_CONNECTION', 'ai-dev-api'),
    'sqlite' => [
        'database' => env('AI_DEV_API_SQLITE_DATABASE', database_path('ai-dev-api.sqlite')),
        'optimize' => env('AI_DEV_API_SQLITE_OPTIMIZE', true),
        'journal_mode' => env('AI_DEV_API_SQLITE_JOURNAL_MODE', 'WAL'),
        'synchronous' => env('AI_DEV_API_SQLITE_SYNCHRONOUS', 'NORMAL'),
        'busy_timeout_ms' => env('AI_DEV_API_SQLITE_BUSY_TIMEOUT_MS', 5000),
        'cache_size_kb' => env('AI_DEV_API_SQLITE_CACHE_SIZE_KB', 20000),
    ],
],
```

Internal migration dosyaları package connection üzerinde mevcut package tablolarını tolere edecek şekilde idempotent tasarlanmıştır. Host application migrations içine publish edilmeleri gerekmez.

## Güvenlik Modeli

- Provider API key değerleri persistence öncesinde Laravel `Crypt` ile encrypt edilir.
- CLI output yalnızca masked credential gösterir.
- Runtime custom provider URL değerleri public HTTPS endpoint olmak zorundadır.
- Runtime custom provider header adları authentication header veya token/secret/password benzeri isim taşıyamaz.
- Provider model cache refresh, authentication failure durumunda key'i invalid işaretler ve auth failure için curated fallback cache üretmez.
- Usage logging, exception message içindeki bearer token değerlerini persistence öncesi redact eder.
- Streaming parser line ve aggregate event byte limitlerini uygular.
- SQLite PRAGMA config değerleri execute edilmeden önce clamp edilir.

## Upgrade Notları

Önceki sürümlerde host application migrations için publish edilebilir migration stub dosyaları vardı. Mevcut install akışı package-owned migration dosyalarını internal tutar ve `ai-dev-api:install` ile package connection üzerinde çalıştırır.

Daha önce package migration stub dosyalarını host uygulamanın `database/migrations` dizinine publish ettiysen ve AI Dev API verilerini host tablolarında tuttuysan, yeni install command bu verileri dedicated package database içine otomatik taşımaz. Mevcut provider key, usage row, model cache row veya setting kayıtlarını korumak için:

1. Host database backup al.
2. Paketi install veya update et.
3. Package storage hazırlamak için `php artisan ai-dev-api:install` çalıştır.
4. Eski host satırlarını package connection tablolarına kopyalayan tek seferlik migration veya command yazıp çalıştır.
5. Migration sonrası provider-key encryption, model cache freshness ve usage analytics çıktısını doğrula.

## Development ve Validation

```bash
composer format:check
composer analyse
composer test
composer ci
```

`composer ci`, formatting check, PHPStan analysis ve full Pest test suite komutlarını birlikte çalıştırır.

## Troubleshooting

### Package SQLite database dosyası yok

Şunu çalıştır:

```bash
php artisan ai-dev-api:install
```

Custom path kullanıyorsan `AI_DEV_API_SQLITE_DATABASE` değerinin writable bir path gösterdiğini ve parent directory'nin application user tarafından oluşturulabildiğini doğrula.

### Provider key invalid işaretlendi

Model refresh veya routed request sırasında authentication failure alınırsa seçilen key invalid işaretlenir. Provider credential değerini provider tarafında doğruladıktan sonra yeni key ekle veya mevcut key'i güncelleyip tekrar enable et.

### Cached modeller görünmüyor

`ai-dev-api:provider:models`, yalnızca seçilen provider key için routable, enabled, non-invalid ve non-expired cache row kayıtlarını gösterir. Seçilen key cache'ini refresh et ve provider adapter implement edilmiş mi kontrol et.

### Streaming tools request'i provider stream açılmadan fail ediyor

Streaming tool calls bilinçli olarak desteklenmez. Tool kullanımı gerekiyorsa non-streaming text generation akışını kullan.

### Custom provider base URL reddediliyor

URL'nin public HTTPS kullandığını, credential/query/fragment içermediğini, localhost veya private/reserved network hedeflemediğini ve yalnızca public IP adreslerine resolve olduğunu doğrula.
