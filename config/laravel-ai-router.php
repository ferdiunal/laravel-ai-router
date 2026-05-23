<?php

declare(strict_types=1);

return [
    'driver' => env('LARAVEL_AI_ROUTER_DRIVER', 'laravel-ai-router'),

    'models' => [
        'text' => [
            'default' => env('LARAVEL_AI_ROUTER_DEFAULT_MODEL', 'auto'),
        ],
        'cache_ttl_minutes' => env('LARAVEL_AI_ROUTER_MODELS_CACHE_TTL', 1440),
    ],

    'routing' => [
        'max_attempts' => env('LARAVEL_AI_ROUTER_MAX_ATTEMPTS', 20),
        'cooldown_seconds' => env('LARAVEL_AI_ROUTER_COOLDOWN_SECONDS', 120),
        'penalty_per_retryable_failure' => env('LARAVEL_AI_ROUTER_PENALTY_PER_FAILURE', 3),
        'max_penalty' => env('LARAVEL_AI_ROUTER_MAX_PENALTY', 10),
    ],

    'streaming' => [
        'max_line_bytes' => env('LARAVEL_AI_ROUTER_STREAM_MAX_LINE_BYTES', 65_536),
        'max_event_bytes' => env('LARAVEL_AI_ROUTER_STREAM_MAX_EVENT_BYTES', 1_048_576),
    ],

    'usage' => [
        'retention_days' => env('LARAVEL_AI_ROUTER_USAGE_RETENTION_DAYS', 30),
    ],

    'rate_windows' => [
        'retention_days' => env('LARAVEL_AI_ROUTER_RATE_WINDOW_RETENTION_DAYS', 7),
    ],

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

    'providers' => [
        'openrouter' => [
            'headers' => [
                'HTTP-Referer' => env('LARAVEL_AI_ROUTER_OPENROUTER_REFERER', config('app.url')),
                'X-Title' => env('LARAVEL_AI_ROUTER_OPENROUTER_TITLE', 'Laravel AI Router'),
            ],
        ],

        'custom' => [
            // 'my-openai-proxy' => [
            //     'name' => 'My OpenAI-compatible Proxy',
            //     'base_url' => env('LARAVEL_AI_ROUTER_MY_PROXY_BASE_URL'),
            //     'headers' => [
            //         // Metadata/proxy headers only; auth-bearing headers are rejected.
            //         'X-Title' => 'Laravel AI Router',
            //     ],
            //     'timeout_ms' => 30000,
            //     'requires_placeholder_key' => false,
            // ],
        ],
    ],
];
