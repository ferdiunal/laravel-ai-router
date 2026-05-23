# Changelog

All notable changes to `laravel-ai-router` will be documented in this file.

## 0.1.0 - Unreleased

- Added the `laravel-ai-router` Laravel AI text provider and driver registration.
- Added `auto` model routing with provider key selection, rate windows, cooldowns, and fallback penalties.
- Added encrypted provider key management commands using Laravel Prompts.
- Added provider + label based free model cache and `LaravelAiRouterProvider::models()` access.
- Added OpenAI-compatible non-streaming and streaming text gateway support.
- Added usage logging, analytics command, and provider/model/error breakdowns.
- Added SQLite optimizer with config-controlled PRAGMA settings.
- Added package migrations, config publishing, Testbench coverage, Pest tests, Pint, and PHPStan/Larastan level 6 gates.
