<?php
return [
    // Default AI provider (openai or gemini)
    'default'       => env('AI_AGENT_DRIVER', 'openai'),
    // Default model to use if not specified
    'default_model' => env('AI_AGENT_MODEL', 'gpt-3.5-turbo'),
    // Memory configuration
    'memory'        => [
        'enabled'  => env('AI_AGENT_MEMORY_ENABLED', true),
        'max_size' => env('AI_AGENT_MEMORY_SIZE', 10), // Number of messages to keep in memory
    ],
    // Database schema configuration
    'database'      => [
        'auto_load_schema' => env('AI_AGENT_LOAD_DB_SCHEMA', true),
        'exclude_tables'   => [
            'migrations',
            'password_resets',
            'personal_access_tokens',
            'failed_jobs',
        ],
    ],
    // AI Providers configuration
    'providers'     => [
        'openai' => [
            'driver'        => 'openai',
            'api_key'       => env('OPENAI_API_KEY'),
            'default_model' => env('AI_AGENT_MODEL', 'gpt-3.5-turbo'),
        ],
        'gemini' => [
            'driver'        => 'gemini',
            'project_id'    => env('GOOGLE_CLOUD_PROJECT_ID'),
            'credentials'   => env('GOOGLE_APPLICATION_CREDENTIALS'),
            'default_model' => env('GEMINI_DEFAULT_MODEL', 'gemini-pro'),
        ],
    ],
];
