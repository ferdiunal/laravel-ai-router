# Changelog

All notable changes to `laravel-ai-router` will be documented in this file.

## v0.4.10 - 2026-05-25

### Fixes

- Hardens OpenAI-compatible custom provider transport for dual-stack gateway hosts by preferring IPv4 DNS pins when available, while preserving IPv6-only fallback.
- Forces identity response encoding so gateways that incorrectly advertise gzip do not fail during cURL/Guzzle decompression.
- Prevents custom extra headers from overriding transport/auth safety headers.

### Verification

- composer ci

## v0.4.9 - 2026-05-24

### Added

- Added `laravel-ai-router:provider-definition:models` to edit declared model lists, live `/models` discovery mode, and chat-based credential validation settings for existing runtime custom OpenAI-compatible provider definitions.
- Added runtime custom-provider config fields for `declared_models`, `models_endpoint_enabled`, `validation_method`, and `validation_model`, including docs for models-less OpenAI-compatible gateways.

### Fixed

- Fixed models-less OpenAI-compatible gateways by allowing declared model cache rows with source `definition`, exact-model routing without calling `/models`, and optional minimal `POST /chat/completions` key validation.
- Rejected final OpenAI-compatible endpoint URLs such as `/chat/completions`, `/completions`, and `/models` as provider base URLs so runtime definitions must point at the API root.
- Preserved previously selected auto-routing cache rows across provider model refreshes while keeping declared model rows out of `auto` unless their metadata explicitly sets `auto_enabled` or the operator selects them later.

### Validation

- `vendor/bin/pest tests/Feature/CustomOpenAiCompatibleProviderTest.php --colors=never` — 49 passed, 98 assertions.
- `vendor/bin/pest tests/Feature/ModelRouterSelectedProviderRoutingTest.php --colors=never` — 6 passed, 11 assertions.
- `vendor/bin/pest tests/Feature/Console/ProviderSyncCommandTest.php --colors=never` — 6 passed, 40 assertions.
- `vendor/bin/pest tests/Unit/Adapters/OpenAiCompatibleAdapterTest.php --colors=never` — 9 passed, 17 assertions.
- `vendor/bin/pest tests/Feature/Console/ProviderModelsCommandTest.php --colors=never` — 1 passed, 3 assertions.
- `composer validate --strict --no-ansi` — valid.
- `composer format:check --no-ansi` — 116 files passed.
- `composer analyse --no-ansi` — no errors.
- `composer test --no-ansi` — 201 passed, 1 skipped, 640 assertions.
- `composer ci --no-ansi` — 201 passed, 1 skipped, 640 assertions.
- `git diff --check` — clean.

**Full Changelog**: https://github.com/ferdiunal/laravel-ai-router/compare/v0.4.8...v0.4.9

## v0.4.8 - 2026-05-24

### Added

- Added `laravel-ai-router:provider:sync` to validate stored provider credentials, optionally refresh provider model caches, and report secret-free local quota readiness snapshots for explicit targets (`--all`, `--provider=`, or `--key-id=`). Default table output is model-level, showing each selected auto-routing model's blocked state, RPM/RPD/TPM/TPD remaining limits, and cooldown.

### Validation

- `vendor/bin/pest tests/Feature/Console/ProviderSyncCommandTest.php tests/Feature/Console/ProviderModelsCommandTest.php tests/Feature/ProviderModelCacheTest.php --colors=never` — 16 passed, 82 assertions.
- `composer validate --strict --no-ansi` — valid.
- `composer ci --no-ansi` — 193 passed, 1 skipped, 606 assertions.
- `git diff --check` — clean.

**Full Changelog**: https://github.com/ferdiunal/laravel-ai-router/compare/v0.4.7...v0.4.8

## v0.4.7 - 2026-05-24

### Fixed

- Restricted provider-key scoped `auto` selection for Google AI Studio and Cloudflare Workers AI to chat-compatible safe rows so provider model discovery can keep embeddings, media, live/Interactions, preview, or unprobed rows visible for exact routing without allowing them to break `ai()->using('laravel-ai-router', 'auto')`.
- Added a router-side compatibility guard so previously selected incompatible cache rows are ignored by `random_provider` auto routing instead of causing 400s such as Google Interactions-only or Cloudflare non-chat model errors.

### Validation

- `composer ci --no-ansi` — 188 passed, 1 skipped, 574 assertions.

**Full Changelog**: https://github.com/ferdiunal/laravel-ai-router/compare/v0.4.6...v0.4.7

## v0.4.6 - 2026-05-24

### Changed

- Changed the default `auto` strategy to `random_provider`, which randomizes selected provider keys first and then selected models inside the chosen provider so one provider with many selected models does not dominate routing by model count.
- Added provider-key scoped model selection for `laravel-ai-router:provider:add` and `laravel-ai-router:provider:models`; selected rows are stored with the cached model `auto_enabled` flag and unselected cached rows remain available for exact model IDs.
- Preserved bootstrap compatibility by falling back to the legacy fallback pool while no provider model cache has been initialized, while failing closed once cached rows exist but no selected model can be routed.
- Documented the selected provider/model pool, legacy `random`, `priority`, and `balanced_random` strategy choices in English and Turkish READMEs.

### Validation

- `composer validate --strict --no-ansi` — valid.
- `composer ci --no-ansi` — 179 passed, 1 skipped, 562 assertions.
- `git diff --check` — clean.

**Full Changelog**: https://github.com/ferdiunal/laravel-ai-router/compare/v0.4.5...v0.4.6

## v0.4.5 - 2026-05-24

### Changed

- Changed default `auto` routing to full fallback-candidate random rotation while preserving the existing priority and bounded `balanced_random` strategies as explicit options.
- Enabled refreshed live cached provider models for `auto` fallback participation when the provider has a routable adapter, instead of limiting auto participation to free/custom metadata.
- Added a package-storage backfill migration to enable or create fallback rows for existing enabled cached provider models with non-invalid provider keys.

### Validation

- `composer validate --strict --no-ansi` — valid; Composer emitted PHP 8.4 deprecation notices from the global Composer phar.
- `composer ci --no-ansi` — 166 passed, 1 skipped, 526 assertions; Composer emitted PHP 8.4 deprecation notices from the global Composer phar.
- `git diff --check` — clean.

**Full Changelog**: https://github.com/ferdiunal/laravel-ai-router/compare/v0.4.4...v0.4.5

## v0.4.4 - 2026-05-24

### Fixed

- Stabilized the Cloudflare account-ID prompt regression test across Windows and non-Windows CI runners by asserting the Laravel Prompts fallback prompt metadata directly.

### Validation

- `composer validate --strict` — valid.
- `composer ci` — 158 passed, 1 skipped, 507 assertions.
- `git diff --check` — clean.

**Full Changelog**: https://github.com/ferdiunal/laravel-ai-router/compare/v0.4.3...v0.4.4

## v0.4.3 - 2026-05-24

### Fixed

- Changed Cloudflare Workers AI provider-key setup to ask for account ID separately from the API token.
- Store Cloudflare account IDs in provider-key `credential_metadata` while encrypting only the API token in the key field; routing/model discovery still composes the adapter-facing `account_id:api_token` credential internally.
- Preserve backwards compatibility by splitting legacy `account_id:api_token` input into separate storage fields when adding Cloudflare keys.

### Validation

- `composer validate --strict` — valid.
- `composer ci` — 158 passed, 1 skipped, 506 assertions.
- `git diff --check` — clean.

**Full Changelog**: https://github.com/ferdiunal/laravel-ai-router/compare/v0.4.2...v0.4.3

## v0.4.2 - 2026-05-24

**Full Changelog**: https://github.com/ferdiunal/laravel-ai-router/compare/v0.4.1...v0.4.2

## v0.4.1 - 2026-05-24

### Fixed

- Fixed provider setup prompts so native routable adapters such as Google AI Studio and Cloudflare Workers AI are offered alongside OpenAI-compatible providers.
- Replaced the prompt-only hardcoded adapter allowlist with `ProviderAdapterRegistry::has(...)` so future implemented native adapters appear automatically.
- Added regression coverage proving `laravel-ai-router:provider:add` can select the newly built-in Google provider path.

### Validation

- `composer validate --strict` — valid.
- `composer ci` — 150 passed, 1 skipped, 487 assertions.
- `git diff --check` — clean.

## v0.4.0 - 2026-05-24

### Summary

- Added native Google AI Studio/Gemini routing with `generateContent`, `streamGenerateContent`, model discovery, system instruction mapping, generation config mapping, and function-call/tool-call normalization.
- Added Cloudflare Workers AI routing through the account-scoped OpenAI-compatible endpoint, including `account_id:api_token` key handling, token verification, model search cache mapping, and null-content normalization for assistant/tool messages.
- Added a balanced random `auto` routing strategy that can shuffle only the top safe fallback pool while preserving exact-model routing and existing eligibility filters.
- Updated English and Turkish documentation for built-in routable providers, Cloudflare key shape, and balanced routing configuration.

### Validation

- `composer validate --strict` — valid.
- `composer ci` — 149 passed, 1 skipped, 486 assertions.
- `git diff --check` — clean.

## v0.3.0 - 2026-05-24

### Summary

- Added a global `ai()` convenience helper with `using(...)->prompt(...)->asText()`, `response()`, `stream()`, and tool attachment support for Tinker and small call sites.
- Added detailed English and Turkish README usage examples for the helper, native Laravel AI agents, non-streaming function tools, streaming, and structured output.
- Preserved native Laravel AI SDK access through `ai()->manager()` and method proxying on the helper wrapper.

### Validation

- `composer validate --strict` — valid.
- `composer ci` — 134 passed, 1 skipped, 447 assertions.
- `git diff --check` — clean.

## v0.2.1 - 2026-05-24

### Summary

- Classify NVIDIA live `/models` entries as free credit-backed choices in provider model cache/listing output.
- Preserve safe auto-routing behavior: newly discovered built-in NVIDIA live models stay out of `auto` fallback unless curated or explicitly allowed by provider policy.
- Add regression coverage for NVIDIA cache metadata, exact model routing, auto fallback safety, and opt-in live smoke documentation.

### Validation

- `composer ci` — 132 passed, 1 skipped, 443 assertions.
- `git diff --check` — clean.

## v0.2.0 - 2026-05-23

### Changed

- Generalized live `/models` cache exposure for routable OpenAI-compatible providers: non-`:free` model IDs are cached as available credits-based rows, routable by exact model ID, and excluded from default `auto` fallback unless explicitly eligible.
- Added opt-in NVIDIA live model discovery smoke coverage guarded by `LARAVEL_AI_ROUTER_LIVE_NVIDIA_MODELS` and `NVIDIA_API_KEY`/`NVAPI_API_KEY`.
- Documented available-model discovery and routing behavior in both English and Turkish READMEs.

### Validation

- Local `git diff --check origin/main..HEAD` before push
- Local `composer validate --strict`
- Local `composer ci`: 131 passed, 1 skipped, 438 assertions
- GitHub Actions Tests matrix on `main`: Ubuntu/Windows x prefer-stable/prefer-lowest passed

## v0.1.2 - 2026-05-23

### Fixed

- Removed the unused `spatie/ray` development dependency so PHP 8.4 deprecation annotations from Ray no longer appear in the CI matrix.
- Pinned the Windows CI lane to `windows-2022` to avoid GitHub runner redirect notices while keeping Windows coverage active.

### Validation

- Local `composer validate --strict`
- Local `composer ci`
- GitHub Actions Tests matrix on `main`: Ubuntu/Windows x prefer-stable/prefer-lowest passed with 0 check-run annotations

## v0.1.1 - 2026-05-23

### Fixed

- Stabilized the CI matrix across Ubuntu and Windows for PHP 8.4.
- Raised the Laravel Pint development dependency floor so `prefer-lowest` uses the project's canonical formatting rules.
- Made the Pest architecture assertion compatible with the lowest supported dependency set.
- Forced repository text files to LF checkout behavior for Windows formatter portability.
- Serialized PHPStan/Larastan analysis to avoid Orchestra Testbench bootstrap cache races on Windows.
- Normalized SQLite path assertions in tests so Windows path separators do not fail the suite.
- Avoided configuring Composer with a repo-scoped GitHub Actions token for public package downloads, while still supporting an optional `COMPOSER_GITHUB_TOKEN` secret.

### Validation

- Local `composer ci`
- GitHub Actions Tests matrix on `main`: Ubuntu/Windows x prefer-stable/prefer-lowest passed

## v0.1.0 - 2026-05-23

### Highlights

- Laravel AI SDK text provider and driver registration with default `auto` model routing.
- Provider API-key management with encrypted storage, labels, enable/disable/remove flows, and anonymous placeholder-key support.
- Runtime custom OpenAI-compatible provider definitions with prompt-based management and SSRF-safe validation.
- Provider + label scoped free-model cache refresh and `LaravelAiRouterProvider::models()` access.
- OpenAI-compatible non-streaming/streaming text gateway, structured output mapping, non-stream tool-call loops, and failover exception mapping.
- Bounded internal retry/failover with local rate windows, cooldowns, fallback penalties, and max-attempt protection.
- Usage analytics, package-owned SQLite storage, PRAGMA optimization, retention config, and `laravel-ai-router:prune` maintenance command.

### Validation

- `composer ci`
- Pest: 128 passed / 416 assertions
- Pint and PHPStan/Larastan level 6 passed

## 0.1.0 - 2026-05-23

- Added the `laravel-ai-router` Laravel AI SDK text provider and driver registration with default `auto` model routing.
- Added provider API-key management commands using Laravel Prompts, encrypted key storage, masked output, provider labels, enable/disable/remove flows, and placeholder-key support for anonymous providers.
- Added runtime custom OpenAI-compatible provider definitions with prompt-based add/list/enable/disable/remove commands, config + database catalog merging, and SSRF-safe base URL/header validation.
- Added provider + label scoped free-model cache refresh, runtime fallback row creation, and `LaravelAiRouterProvider::models()` access to cached model IDs.
- Added OpenAI-compatible non-streaming and streaming text gateway support, structured output mapping, non-stream function tool-call loops, and Laravel AI failover exception mapping.
- Added bounded internal retry/failover across eligible provider keys with local rate windows, cooldowns, fallback penalties, and max-attempt protection.
- Added usage logging and analytics grouped by provider, label, model, status, token counts, latency, error category, and request timestamp.
- Added package-owned storage with internal migrations, config publishing, dedicated SQLite defaults, WAL/foreign-key/busy-timeout/cache-size PRAGMA optimization, retention config, and `laravel-ai-router:prune` maintenance command.
- Added README/README.TR documentation clarifying that the package is a Laravel AI text-provider router, not a standalone OpenAI-compatible HTTP proxy.
- Added CI/release hygiene with manual-only style-fix workflow, conservative Dependabot patch auto-merge, Testbench coverage, Pest tests, Pint, and PHPStan/Larastan level 6 gates.
