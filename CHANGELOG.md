# Changelog

All notable changes to `laravel-ai-router` will be documented in this file.

## Unreleased

### Changed

- Generalized live `/models` cache exposure for routable OpenAI-compatible providers: non-`:free` model IDs are cached as available credits-based rows, routeable by exact model ID, and excluded from default `auto` fallback unless explicitly eligible.
- Added opt-in NVIDIA live model discovery smoke coverage guarded by `LARAVEL_AI_ROUTER_LIVE_NVIDIA_MODELS` and `NVIDIA_API_KEY`/`NVAPI_API_KEY`.

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
