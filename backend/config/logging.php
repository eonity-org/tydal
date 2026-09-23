<?php

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => ['stream' => 'php://stderr'],
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        /*
        |----------------------------------------------------------------------
        | AI Activity channel
        |----------------------------------------------------------------------
        |
        | Dedicated rotating log for AI pipeline debug traces.
        | Only written to when AI_DEBUG=true.
        | Each entry is a single JSON line for easy grep/tail/jq inspection.
        |
        | Inspect: tail -f storage/logs/ai-activity.log | jq .
        |
        */
        'ai_activity' => [
            'driver' => 'daily',
            'path' => storage_path('logs/ai-activity.log'),
            'level' => 'debug',
            'days' => 7,
            'formatter' => JsonFormatter::class,
            'formatter_with' => ['appendNewline' => true],
        ],

        /*
        |----------------------------------------------------------------------
        | AI Models channel
        |----------------------------------------------------------------------
        |
        | Concise one-line-per-call log for every external model invocation
        | (autotag, vision, ask-aity RAG). Only active when AI_DEBUG=true.
        |
        | Fields per entry: subject, model, operation, status, duration_ms.
        |
        | Inspect: tail -f storage/logs/ai-models.log | jq '.context'
        |
        */
        'ai_models' => [
            'driver' => 'daily',
            'path' => storage_path('logs/ai-models.log'),
            'level' => 'info',
            'days' => 30,
            'formatter' => JsonFormatter::class,
            'formatter_with' => ['appendNewline' => true],
        ],

    ],

];
