<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('AI_CHAT_ENABLED', false),
    'daily_limit' => (int) env('AI_CHAT_DAILY_LIMIT', 30),
    'history_messages' => (int) env('AI_CHAT_HISTORY_MESSAGES', 12),

    'gemini' => [
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    ],
];
