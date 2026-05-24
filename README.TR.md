![Laravel Ai Router](./art/cover.png)

# Laravel AI Router

Türkçe | [English](README.md)

Laravel AI Router, Laravel AI SDK için geliştirilmiş bir text provider paketidir. Paket, tek bir Laravel AI provider adı (`laravel-ai-router`) üzerinden birden fazla OpenAI-compatible provider anahtarını, provider + label bazlı available-model cache kayıtlarını, fallback model sıralamasını, yerel rate-limit pencerelerini ve usage analytics verisini yönetir.

Paket kendi operasyonel durumunu varsayılan olarak ayrılmış bir paket database connection içinde saklar. Varsayılan storage hedefi `database/laravel-ai-router.sqlite` dosyasıdır. Böylece provider key kayıtları, model cache satırları, fallback routing satırları, rate-limit sayaçları, kullanım kayıtları, runtime custom provider tanımları ve package setting kayıtları host uygulamanın ana tablolarından ayrılır.

## Bu paket nedir / ne değildir

Laravel AI Router, Laravel AI SDK için bir text-provider router paketidir. Standalone OpenAI-compatible HTTP proxy değildir ve `/v1/chat/completions` veya `/v1/models` route'ları expose etmez. Host uygulamalar paketi Laravel AI üzerinden çağırır (`ai()->using('laravel-ai-router', 'auto')`), paket de bu çağrıları configured provider key kayıtlarına route eder.

Bu release içindeki built-in routable provider listesi, implement edilmiş ve testle kapsanmış adapterlarla sınırlıdır:

| Provider | Adapter |
| --- | --- |
| Google AI Studio | Native Gemini `generateContent` / `streamGenerateContent` |
| Cohere | OpenAI-compatible compatibility API |
| Groq | OpenAI-compatible |
| Cerebras | OpenAI-compatible |
| SambaNova | OpenAI-compatible |
| NVIDIA NIM | OpenAI-compatible, varsayılan seed'de disabled |
| Mistral | OpenAI-compatible |
| OpenRouter | OpenAI-compatible |
| GitHub Models | OpenAI-compatible |
| Cloudflare Workers AI | Account-scoped OpenAI-compatible Workers AI endpoint |
| Zhipu AI | OpenAI-compatible |
| Ollama Cloud | OpenAI-compatible |
| Kilo Gateway | OpenAI-compatible, anonymous placeholder key destekli |
| Pollinations | OpenAI-compatible, anonymous placeholder key destekli |
| LLM7 | OpenAI-compatible, anonymous placeholder key destekli |

Google AI Studio Google API-key query authentication kullanır. Cloudflare Workers AI için account ID, API token'dan ayrı istenir; token provider key olarak encrypt edilir, account ID ise credential metadata içinde saklanır. Böylece adapter account-scoped Workers AI URL'lerini kurarken iki değeri tek secret alanına paketlemez.

Free-tier ve anonymous provider'lar limit, model availability, authentication davranışı veya kullanım şartlarını haber vermeden değiştirebilir. Her upstream provider'ın terms, quota ve SLA duruşunu production use case'in için ayrıca doğrulamadıysan free-tier routing'i development/prototype infrastructure olarak ele al.

## Özellikler

- Laravel AI driver adı: `laravel-ai-router`.
- Tinker ve küçük call site'lar için `using(...)->prompt(...)->asText()` convenience flow sağlayan global `ai()` helper.
- Varsayılan text model: `auto`.
- Provider API key yönetimi: ekle, listele, aktif et, pasif et, sil.
- Runtime custom OpenAI-compatible provider yönetimi: ekle, listele, aktif et, pasif et, sil.
- Provider model discovery/cache destekli native Google AI Studio ve Cloudflare Workers AI adapterları.
- Laravel encryption ile encrypted API-key saklama.
- Maskelenmiş CLI çıktısı; raw provider key değerleri yazdırılmaz.
- Provider + label scope'lu, free/credits metadata içeren available-model cache.
- `LaravelAiRouterProvider::models()` ile cached model ID erişimi.
- Laravel AI `TextProvider` üzerinden non-streaming text generation.
- Laravel AI stream eventleri üzerinden streaming text generation.
- Laravel AI structured response tipleriyle structured output desteği.
- Non-stream OpenAI-compatible function tool-call loop desteği.
- Uygun provider key kayıtları arasında bounded internal retry/failover ve retryable provider hataları için Laravel AI failover exception mapping.
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
composer require ferdiunal/laravel-ai-router
php artisan laravel-ai-router:install
```

Install komutu paket kurulumunu şu sırayla yapar:

1. Paket connection SQLite storage hedefliyorsa configured SQLite dosyasını oluşturur.
2. Package-owned internal migration dosyalarını configured package connection üzerinde çalıştırır.
3. Curated model catalog ve fallback ordering satırlarını seed eder.
4. Etkinse ve bağlantı uygunsa güvenli SQLite PRAGMA optimizasyonlarını uygular.
5. Interaktif console ortamında provider-key setup wizard akışını başlatır.

Varsayılan akışta host uygulamanın `database/migrations` dizinine migration publish etmek gerekmez. Paket migration dosyaları internal package migration olarak kalır ve `laravel-ai-router:install` tarafından package connection üzerinde çalıştırılır.

## Config

Sadece varsayılanları değiştirmek istediğinde package config publish et:

```bash
php artisan vendor:publish --tag=laravel-ai-router-config
```

Provider kaydını `config/ai.php` içine ekle:

```php
'providers' => [
    'laravel-ai-router' => [
        'driver' => 'laravel-ai-router',
    ],
],

'default' => 'laravel-ai-router',
```

Package config içinde default text model `auto` olarak kalır:

```php
// config/laravel-ai-router.php
return [
    'driver' => env('LARAVEL_AI_ROUTER_DRIVER', 'laravel-ai-router'),

    'models' => [
        'text' => [
            'default' => env('LARAVEL_AI_ROUTER_DEFAULT_MODEL', 'auto'),
        ],
        'cache_ttl_minutes' => env('LARAVEL_AI_ROUTER_MODELS_CACHE_TTL', 1440),
    ],

    'routing' => [
        // random varsayılandır ve fallback-enabled aday listesinin tamamını karıştırır.
        // priority deterministic fallback sırasını korur; balanced_random sadece configured
        // üst havuzu karıştırır. Normal key, cache ve limit kontrolleri adayın
        // kullanılabilirliğini yine belirler.
        'auto_strategy' => env('LARAVEL_AI_ROUTER_AUTO_STRATEGY', 'random'),
        'random_pool_size' => env('LARAVEL_AI_ROUTER_RANDOM_POOL_SIZE', 5),
        'random_priority_window' => env('LARAVEL_AI_ROUTER_RANDOM_PRIORITY_WINDOW', 3),
    ],
];
```

### Package database connection

Varsayılan package connection adı `laravel-ai-router` değeridir. Bu connection adı kullanıldığında service provider, path override edilmediyse `database/laravel-ai-router.sqlite` dosyasını kullanan dedicated SQLite connection register eder.

```env
# Dedicated package SQLite path. Varsayılan laravel-ai-router connection bu dosyayı kullanır.
LARAVEL_AI_ROUTER_SQLITE_DATABASE=/absolute/path/laravel-ai-router.sqlite

# Opsiyonel: package storage'ı mevcut bir host application connection'a yönlendir.
# config/database.php içinde tanımlı mysql, pgsql veya sqlite gibi bir connection adı kullan.
LARAVEL_AI_ROUTER_DB_CONNECTION=mysql
```

`LARAVEL_AI_ROUTER_DB_CONNECTION` değerini sadece package-owned state'in mevcut bir host connection içinde tutulmasını bilerek istediğinde kullan. `laravel-ai-router` değeri, paketin varsayılan dedicated SQLite connection'ını ifade eder.

## Provider Key Yönetimi

Provider key kayıtları `provider + label` ikilisiyle ayrılır. Böylece aynı provider için birden fazla key yönetilebilir; örneğin `openrouter / Primary`, `openrouter / Backup` veya `groq / Team`.

```bash
php artisan laravel-ai-router:provider:add
php artisan laravel-ai-router:provider:list
php artisan laravel-ai-router:provider:models
php artisan laravel-ai-router:provider:enable
php artisan laravel-ai-router:provider:disable
php artisan laravel-ai-router:provider:remove
```

`laravel-ai-router:provider:add` Laravel Prompts ile şu girdileri alır:

1. Provider platform.
2. Gerekiyorsa provider-specific credential metadata; örneğin Cloudflare account ID.
3. API key veya API token.
4. Provider-key label.
5. Otomatik model-cache refresh sonrası cached available modeller içinden opsiyonel default model seçimi.

Raw API key persistence öncesi encrypt edilir ve command output içinde hiçbir zaman gösterilmez. Listeleme ve prompt ekranlarında sadece masked credential kullanılır.

Cloudflare Workers AI için `account_id`, `credential_metadata` içinde ayrı saklanır; key alanında sadece API token encrypt edilir. Routing ve model discovery sırasında paket adapter'a gidecek `account_id:api_token` credential'ını içeride üretir, upstream bearer credential olarak sadece token kısmını gönderir. Eski `account_id:api_token` girdileri de key eklenirken ayrı storage alanlarına bölünür.

## Runtime Custom OpenAI-compatible Provider'lar

Runtime custom provider desteği, package code değiştirmeden OpenAI-compatible gateway, proxy veya provider eklemeyi sağlar.

```bash
php artisan laravel-ai-router:provider-definition:add
php artisan laravel-ai-router:provider-definition:list
php artisan laravel-ai-router:provider-definition:enable
php artisan laravel-ai-router:provider-definition:disable
php artisan laravel-ai-router:provider-definition:remove

php artisan laravel-ai-router:provider:add
php artisan laravel-ai-router:provider:models
```

Runtime provider definition alanları:

- Provider slug, örnek: `my-openai-proxy`.
- Görünen ad.
- OpenAI-compatible base URL, örnek: `https://api.example.com/v1`.
- Opsiyonel metadata headers JSON, örnek: `{"X-Title":"Laravel AI Router"}`.
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
// config/laravel-ai-router.php
'providers' => [
    'custom' => [
        'my-openai-proxy' => [
            'name' => 'My OpenAI Proxy',
            'base_url' => 'https://api.example.com/v1',
            'headers' => [
                'X-Title' => 'Laravel AI Router',
            ],
            'timeout_ms' => 30000,
            'requires_placeholder_key' => false,
        ],
    ],
],
```

Routable OpenAI-compatible provider `/models` endpointinden model ID döndürürse Laravel AI Router bu model ID'leri provider + label bazında cacheleyebilir ve exact model routing içinde kullanılabilmeleri için runtime model/fallback satırlarını oluşturabilir.

## Model Cache ve Default Model Tercihi

Provider model cache kayıtları provider key scope'undadır. Bir cache satırı provider platform, provider label, model ID, display name, context window, rate limits, token limits, free-tier bilgisi, tool support bilgisi, source ve refresh timestamp alanlarını saklar.

Cache refresh ve listeleme için:

```bash
php artisan laravel-ai-router:provider:models
```

Bu komut seçilen key için cache refresh yapabilir, cached available modelleri listeleyebilir ve searchable prompt ile default model seçtirebilir. Model listeleme ve default seçim akışı yalnızca şu kriterleri sağlayan kayıtları gösterir:

- Provider platform için routable adapter vardır.
- Provider key enabled durumdadır.
- Provider key status değeri `invalid` değildir.
- Provider key model cache süresi dolmamıştır.
- Cache row provider key ID, platform ve label ile eşleşir.
- Cache row enabled durumdadır.

Live model discovery routable provider'lar için geneldir: `/models` içinden gelen geçerli satırlar ID değeri `:free` ile bitmese bile cachelenir. Free-tier bilgisi metadata olarak saklanır (`is_free`); NVIDIA NIM live satırları free credit-backed model olarak işaretlenir (`free` + `credits-based`), diğer non-free live satırlar varsayılan olarak `credits-based` budget label alır. Exact live model ID'ler doğrudan route edilebilir. Yeni routable live satırlar için fallback row da enabled olur; böylece varsayılan `auto` stratejisi cached provider/model seçenekleri arasında dönebilir. Key-backed provider'ları production'da kullanmadan önce upstream quota ve billing şartlarını ayrıca gözden geçir.

Package config default değeri `auto` olarak kalır. Kullanıcı CLI üzerinden default model seçtiğinde seçim package settings tablosuna yazılır ve runtime sırasında `LaravelAiRouterProvider::defaultTextModel()` tarafından okunur. Bu işlem config dosyalarını değiştirmez.

Kod içinden model erişimi:

```php
use Ferdiunal\LaravelAiRouter\LaravelAiRouterProvider;
use Laravel\Ai\AiManager;

$provider = app(AiManager::class)->textProvider('laravel-ai-router');

assert($provider instanceof LaravelAiRouterProvider);

$modelIds = $provider->models('openrouter', 'Primary');
// ['auto', 'paid/model', 'qwen/qwen3-coder:free', ...]
```

## Prompt Kullanımı

Prompt göndermeden önce install/setup akışını çalıştırıp en az bir enabled provider key ekle:

```bash
php artisan laravel-ai-router:install
php artisan laravel-ai-router:provider:add
```

Laravel AI Router iki şekilde çağrılabilir:

1. Paketin convenience helper'ı: `ai()->using(...)->prompt(...)->asText()`.
2. Native Laravel AI agent sınıfları: `Laravel\Ai\Contracts\Agent` implement eden ve `Laravel\Ai\Promptable` kullanan class'lar.

### Tinker'da hızlı `ai()` helper kullanımı

Paket, Tinker ve küçük application call site'ları için global `ai()` helper fonksiyonu sağlar. Bu helper Laravel AI Router'ın convenience helper'ını döndürür; Laravel AI SDK facade'inin birebir aynısı değildir.

```bash
php artisan tinker
```

```php
ai()
    ->using('laravel-ai-router', 'auto')
    ->instructions('Kısa cevap ver ve operasyonel olarak önemli detayları ekle.')
    ->prompt('Bu destek kaydını üç maddeyle özetle.')
    ->asText();
```

`auto`, Laravel AI Router'ın uygun provider key ve fallback-enabled cached model seçmesini sağlar. Varsayılan auto zinciri `routing.auto_strategy=random` kullanır; her route denemesinde enabled fallback listesinin tamamını karıştırır ama disabled/invalid key skip, model-cache uyumluluğu, cooldown ve rate/token-limit kontrollerini yine uygular. Deterministic sıra istiyorsan `LARAVEL_AI_ROUTER_AUTO_STRATEGY=priority`, sadece configured üst fallback havuzunu karıştırmak istiyorsan `balanced_random` (`LARAVEL_AI_ROUTER_RANDOM_POOL_SIZE`, `LARAVEL_AI_ROUTER_RANDOM_PRIORITY_WINDOW`) kullan. Exact model çağrıları varsayılan olarak yalnızca istenen model ID içinde route edilir.

Cached exact model ID ile de route edebilirsin:

```php
ai()
    ->using('laravel-ai-router', 'qwen/qwen3-coder:free')
    ->prompt('Kısa bir release note yaz.')
    ->asText();
```

Sadece text değil, tam Laravel AI response objesine ihtiyaç duyduğunda `response()` kullan:

```php
$response = ai()
    ->using('laravel-ai-router', 'auto')
    ->timeout(20)
    ->prompt('Sipariş durumunu özetle.')
    ->response();

(string) $response;              // response text
$response->usage->promptTokens;  // input token sayısı
$response->usage->completionTokens;
$response->meta->provider;       // "laravel-ai-router"
$response->meta->model;          // route edilen upstream model ID
```

Low-level SDK erişimi gerektiğinde helper içindeki Laravel AI manager'a erişebilirsin:

```php
use Ferdiunal\LaravelAiRouter\LaravelAiRouterProvider;

$provider = ai()->manager()->textProvider('laravel-ai-router');

assert($provider instanceof LaravelAiRouterProvider);

$models = $provider->models('openrouter', 'Primary');
// ['auto', 'paid/model', 'qwen/qwen3-coder:free', ...]
```

`ai()` üzerinde bilinmeyen method çağrıları underlying `Laravel\Ai\AiManager` instance'ına proxy edilir. Bu yüzden `ai()->textProvider('laravel-ai-router')` da çalışır.

### Native Laravel AI agent kullanımı

Production kodunda named agent class genelde daha test edilebilir ve tekrar kullanılabilir olur:

```php
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Provider('laravel-ai-router')]
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

```php
$response = SupportAgent::make()->prompt('Sipariş durumunu özetle.');

echo (string) $response;
```

Provider/model değerini call bazında override edebilirsin:

```php
$response = SupportAgent::make()->prompt(
    'Sipariş durumunu özetle.',
    provider: 'laravel-ai-router',
    model: 'qwen/qwen3-coder:free',
    timeout: 20,
);
```

Laravel AI provider array ile failover kullanımı:

```php
$response = SupportAgent::make()->prompt(
    'Sipariş durumunu özetle.',
    provider: [
        'laravel-ai-router' => 'auto',
        'openai' => 'gpt-4o-mini',
    ],
    timeout: 20,
);
```

Retryable rate-limit, geçici overload, timeout ve insufficient-credit durumları mümkün olduğunda Laravel AI failover-compatible exception tiplerine map edilir.

## Function Tool Kullanımı

Laravel AI Router, non-streaming OpenAI-compatible tool call destekler. Tool'lar normal Laravel AI `Tool` class'larıdır; router schema bilgisini OpenAI-compatible `tools` payload'una map eder, provider'ın istediği tool call'ları local olarak çalıştırır ve tool result mesajlarını seçilen provider'a geri gönderir.

> Streaming tool call henüz desteklenmez. Tool workflow'ları için `prompt()` / `asText()` / `response()` kullan. Tool ile `stream()` çağırmak upstream stream açılmadan fail eder.

### Tool tanımla

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class LookupOrder implements Tool
{
    public function name(): string
    {
        return 'lookup_order';
    }

    public function description(): string
    {
        return 'Order ID ile sipariş toplamını bulur.';
    }

    public function handle(Request $request): string
    {
        $orderId = $request['order_id'];

        // Buraya kendi application lookup logic'ini koy.
        return "{$orderId} sipariş toplamı 42 TRY";
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->string()->required(),
        ];
    }
}
```

`name()` metodu `Tool` contract içinde zorunlu değildir ama tavsiye edilir. Eksik olursa Laravel AI tool adını class basename üzerinden üretir.

### `ai()` helper ile tool kullanımı

```php
$response = ai()
    ->using('laravel-ai-router', 'auto')
    ->instructions('Sipariş sorularını cevaplamak için gerektiğinde tool kullan.')
    ->withTools([new LookupOrder])
    ->prompt('A-100 siparişinin toplamı nedir?')
    ->response();

echo (string) $response;
// "A-100 siparişinin toplamı 42 TRY."

$response->toolCalls;   // ToolCall objelerinden oluşan Collection
$response->toolResults; // ToolResult objelerinden oluşan Collection
$response->steps;       // Tool loop iterasyonları dahil Step Collection
```

### Named agent ile tool kullanımı

```php
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

#[Provider('laravel-ai-router')]
#[Model('auto')]
final class OrderAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Sipariş sorularını cevaplamak için gerektiğinde tool kullan.';
    }

    /** @return iterable<int, Tool> */
    public function tools(): iterable
    {
        return [new LookupOrder];
    }
}
```

```php
$response = OrderAgent::make()->prompt('A-100 siparişinin toplamı nedir?');

echo (string) $response;
```

## Streaming Kullanımı

Laravel AI Router, Laravel AI stream eventleri üzerinden text streaming destekler:

```php
use Laravel\Ai\Streaming\Events\TextDelta;

$stream = ai()
    ->using('laravel-ai-router', 'auto')
    ->instructions('Kısa bir streaming cevap ver.')
    ->prompt('Mevcut sipariş durumunu açıkla.')
    ->stream();

foreach ($stream as $event) {
    if ($event instanceof TextDelta) {
        echo $event->delta;
    }
}
```

Aynı davranış named agent üzerinden de kullanılabilir:

```php
$stream = SupportAgent::make()->stream(
    'Mevcut sipariş durumunu açıkla.',
    provider: 'laravel-ai-router',
    model: 'auto',
);
```

## Structured Output Kullanımı

Laravel AI Router, non-streaming text request'lerde Laravel AI structured output destekler. Paket upstream'e JSON-mode benzeri seçenekler gönderir ve geçerli JSON content'i Laravel AI structured response objelerine map eder.

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Provider('laravel-ai-router')]
#[Model('auto')]
final class TicketAnalyzer implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'İstenen ticket analizi için JSON response döndür.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'priority' => $schema->integer()->required(),
        ];
    }
}
```

```php
$response = TicketAnalyzer::make()->prompt(
    'Bu ticketı analiz et.',
    provider: 'laravel-ai-router',
    model: 'auto',
);

$response['summary'];  // "..."
$response['priority']; // 3
$response->usage;
$response->meta;
```

## Laravel AI SDK Capability Matrix

| Capability | Durum | Not |
| --- | --- | --- |
| Text generation | Desteklenir | Usage ve metadata dahil Laravel AI `TextResponse` veya `StructuredTextResponse` döndürür. |
| Text streaming | Desteklenir | OpenAI-compatible SSE chunk'larını Laravel AI `StreamStart`, `TextStart`, `TextDelta`, `TextEnd` ve `StreamEnd` eventlerine çevirir. |
| Structured output | Desteklenir | JSON-mode benzeri seçenekleri gönderir ve geçerli JSON içeriğini Laravel AI structured response tiplerine map eder. |
| Function tools | Non-stream desteklenir | OpenAI-compatible `tools` / `tool_calls` loop'unu çalıştırır ve tool result mesajlarını provider'a geri gönderir. |
| Streaming tools | Desteklenmez | Upstream stream açılmadan açık bir `LogicException` ile fail eder. |
| Failover | Desteklenir | Uygun internal provider key kayıtlarını `routing.max_attempts` sınırına kadar dener; ardından rate limit, insufficient credit/quota, timeout ve overload hatalarını Laravel AI failover exception tiplerine map eder. `auto` varsayılan olarak full random fallback-candidate rotation kullanır; `priority` ve bounded `balanced_random` key/cache/limit eligibility kontrollerini bypass etmeden kullanılabilir. |
| Images, audio, transcription, embeddings, reranking, files, stores | Desteklenmez | Paket yalnızca text provider contract advertise eder ve unsupported methodlar için açık capability hatası fırlatır. |

## Usage Analytics

Usage analytics çıktısı için:

```bash
php artisan laravel-ai-router:usage
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

### Retention ve pruning

Laravel AI Router varsayılan olarak usage satırlarını `LARAVEL_AI_ROUTER_USAGE_RETENTION_DAYS` gün, rate-window/cooldown satırlarını `LARAVEL_AI_ROUTER_RATE_WINDOW_RETENTION_DAYS` gün tutar. Eski package storage satırlarını temizlemek için:

```bash
php artisan laravel-ai-router:prune
```

SQLite package database'i pruning sonrası özellikle compact etmek istiyorsan `--vacuum` opsiyonunu bilinçli olarak kullan:

```bash
php artisan laravel-ai-router:prune --vacuum
```

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
    'connection' => env('LARAVEL_AI_ROUTER_DB_CONNECTION', 'laravel-ai-router'),
    'sqlite' => [
        'database' => env('LARAVEL_AI_ROUTER_SQLITE_DATABASE', database_path('laravel-ai-router.sqlite')),
        'optimize' => env('LARAVEL_AI_ROUTER_SQLITE_OPTIMIZE', true),
        'journal_mode' => env('LARAVEL_AI_ROUTER_SQLITE_JOURNAL_MODE', 'WAL'),
        'synchronous' => env('LARAVEL_AI_ROUTER_SQLITE_SYNCHRONOUS', 'NORMAL'),
        'busy_timeout_ms' => env('LARAVEL_AI_ROUTER_SQLITE_BUSY_TIMEOUT_MS', 5000),
        'cache_size_kb' => env('LARAVEL_AI_ROUTER_SQLITE_CACHE_SIZE_KB', 20000),
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

Önceki sürümlerde host application migrations için publish edilebilir migration stub dosyaları vardı. Mevcut install akışı package-owned migration dosyalarını internal tutar ve `laravel-ai-router:install` ile package connection üzerinde çalıştırır.

Daha önce package migration stub dosyalarını host uygulamanın `database/migrations` dizinine publish ettiysen ve Laravel AI Router verilerini host tablolarında tuttuysan, yeni install command bu verileri dedicated package database içine otomatik taşımaz. Mevcut provider key, usage row, model cache row veya setting kayıtlarını korumak için:

1. Host database backup al.
2. Paketi install veya update et.
3. Package storage hazırlamak için `php artisan laravel-ai-router:install` çalıştır.
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
php artisan laravel-ai-router:install
```

Custom path kullanıyorsan `LARAVEL_AI_ROUTER_SQLITE_DATABASE` değerinin writable bir path gösterdiğini ve parent directory'nin application user tarafından oluşturulabildiğini doğrula.

### Provider key invalid işaretlendi

Model refresh veya routed request sırasında authentication failure alınırsa seçilen key invalid işaretlenir. Provider credential değerini provider tarafında doğruladıktan sonra yeni key ekle veya mevcut key'i güncelleyip tekrar enable et.

### Cached modeller görünmüyor

`laravel-ai-router:provider:models`, yalnızca seçilen provider key için routable, enabled, non-invalid ve non-expired cache row kayıtlarını gösterir. Seçilen key cache'ini refresh et ve provider adapter implement edilmiş mi kontrol et.

### Streaming tools request'i provider stream açılmadan fail ediyor

Streaming tool calls bilinçli olarak desteklenmez. Tool kullanımı gerekiyorsa non-streaming text generation akışını kullan.

### Custom provider base URL reddediliyor

URL'nin public HTTPS kullandığını, credential/query/fragment içermediğini, localhost veya private/reserved network hedeflemediğini ve yalnızca public IP adreslerine resolve olduğunu doğrula.
