# Changelog

All notable changes to `laravel-ai-router` will be documented in this file.

## 0.1.0 - Unreleased

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
