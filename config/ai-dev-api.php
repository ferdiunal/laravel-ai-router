<?php

declare(strict_types=1);

return [
    'driver' => env('AI_DEV_API_DRIVER', 'ai-dev-api'),

    'models' => [
        'text' => [
            'default' => env('AI_DEV_API_DEFAULT_MODEL', 'auto'),
        ],
        'cache_ttl_minutes' => env('AI_DEV_API_MODELS_CACHE_TTL', 1440),
    ],

    'routing' => [
        'max_attempts' => env('AI_DEV_API_MAX_ATTEMPTS', 20),
        'cooldown_seconds' => env('AI_DEV_API_COOLDOWN_SECONDS', 120),
        'sticky_ttl_minutes' => env('AI_DEV_API_STICKY_TTL_MINUTES', 30),
        'penalty_per_retryable_failure' => env('AI_DEV_API_PENALTY_PER_FAILURE', 3),
        'max_penalty' => env('AI_DEV_API_MAX_PENALTY', 10),
    ],

    'streaming' => [
        'max_line_bytes' => env('AI_DEV_API_STREAM_MAX_LINE_BYTES', 65_536),
        'max_event_bytes' => env('AI_DEV_API_STREAM_MAX_EVENT_BYTES', 1_048_576),
    ],

    'database' => [
        'connection' => env('AI_DEV_API_DB_CONNECTION'),
        'sqlite' => [
            'optimize' => env('AI_DEV_API_SQLITE_OPTIMIZE', true),
            'journal_mode' => env('AI_DEV_API_SQLITE_JOURNAL_MODE', 'WAL'),
            'synchronous' => env('AI_DEV_API_SQLITE_SYNCHRONOUS', 'NORMAL'),
            'busy_timeout_ms' => env('AI_DEV_API_SQLITE_BUSY_TIMEOUT_MS', 5000),
            'cache_size_kb' => env('AI_DEV_API_SQLITE_CACHE_SIZE_KB', 20000),
        ],
    ],

    'providers' => [
        'openrouter' => [
            'headers' => [
                'HTTP-Referer' => env('AI_DEV_API_OPENROUTER_REFERER', config('app.url')),
                'X-Title' => env('AI_DEV_API_OPENROUTER_TITLE', 'AI Dev API'),
            ],
        ],
    ],
];
