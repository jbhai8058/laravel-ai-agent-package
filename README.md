# Laravel AI Agent Package

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

> **Copyright © 2025 Muhammad Junaid Rehman Siddiqui**
> 
> All rights reserved. This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
> 
> **Note:** Unauthorized use, modification, or distribution of this software without proper attribution is strictly prohibited and may result in legal action.

A powerful Laravel package for integrating AI agents (OpenAI, Gemini) with database awareness and memory management.

## Features

- 🤖 Multiple AI Providers (OpenAI, Gemini)
- 🧠 Conversation Memory & Context Management
- 🗃️ Database Schema Awareness
- 🔄 Automatic Query Generation
- 🔒 Safe Query Execution
- ⚡ Easy Integration

## Requirements

- PHP 7.4+
- Laravel 8.0+
- Composer
- OpenAI API key or Google Cloud credentials (for Gemini)

## Installation

1. Install via Composer:
   ```bash
   composer require laravelai/smartagent
   ```

2. Run the install command to set up the package:
   ```bash
   php artisan ai-agents:install
   ```
   
   This will:
   - Publish the config file to `config/ai-agents.php`
   - Add required environment variables to your `.env` file

3. Add your API keys to `.env`:
   ```env
   # For OpenAI
   OPENAI_API_KEY=your_openai_api_key
   
   # For Gemini
   GOOGLE_CLOUD_PROJECT_ID=your_project_id
   GOOGLE_APPLICATION_CREDENTIALS=/path/to/credentials.json
   
   # General Settings
   AI_AGENT_DRIVER=openai  # or 'gemini'
   AI_AGENT_MODEL=gpt-4    # or 'gemini-pro'
   ```

## Basic Usage

```php
use LaravelAI\SmartAgent\Facades\AiAgent;

// Simple chat
$response = AiAgent::chat([
    'role' => 'user',
    'content' => 'Hello, how are you?'
]);

// With memory
AiAgent::chat([
    'role' => 'user',
    'content' => 'My name is John'
]);

$response = AiAgent::chat([
    'role' => 'user',
    'content' => 'What is my name?'
]); // Will remember the name is John
```

## Database Integration

```php
// Get query suggestions based on natural language
$result = AiAgent::queryWithDatabaseContext(
    "Show me all active users who made a purchase"
);

// Execute safe query
$users = AiAgent::executeSafeQuery(
    "SELECT * FROM users WHERE active = ?",
    [1]
);
```

## Configuration

Edit `config/ai-agents.php` for advanced configuration:

```php
return [
    'default' => 'openai',
    'database' => [
        'auto_load_schema' => true,
        'exclude_tables' => ['migrations', 'password_resets'],
    ],
    'memory' => [
        'enabled' => true,
        'max_size' => 10
    ]
];
```

## Security

- Only SELECT queries are allowed by default
- All queries are validated against the database schema
- Sensitive information is never logged

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Support

For issues and feature requests, please use the [GitHub Issues](https://github.com/yourusername/laravel-ai-agents/issues) page.
