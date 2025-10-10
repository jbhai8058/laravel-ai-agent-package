# Laravel AI Agent Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/laravelai/smartagent.svg?style=flat-square)](https://packagist.org/packages/laravelai/smartagent)
[![Total Downloads](https://img.shields.io/packagist/dt/laravelai/smartagent.svg?style=flat-square)](https://packagist.org/packages/laravelai/smartagent)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/packagist/php-v/laravelai/smartagent)](https://php.net/)

> **Copyright © 2025 Muhammad Junaid Rehman Siddiqui**
> 
> All rights reserved. This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
> 
> **Note:** Unauthorized use, modification, or distribution of this software without proper attribution is strictly prohibited and may result in legal action.

A powerful Laravel package for integrating AI agents (OpenAI, Gemini) with advanced database awareness, automatic query generation, and safe execution.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

> **Copyright © 2025 Muhammad Junaid Rehman Siddiqui**
> 
> All rights reserved. This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
> 
> **Note:** Unauthorized use, modification, or distribution of this software without proper attribution is strictly prohibited and may result in legal action.

A powerful Laravel package for integrating AI agents (OpenAI, Gemini) with database awareness and memory management.

## Features

- 🤖 **Multiple AI Providers** - Supports OpenAI and Gemini out of the box
- 🧠 **Conversation Memory** - Maintains context across multiple interactions
- 🗃️ **Schema-Aware** - Understands your database structure
- 🔄 **Smart Query Generation** - Converts natural language to SQL
- 🔗 **JOIN Support** - Automatically generates complex JOIN queries
- 🔒 **Safe Execution** - Validates and sanitizes all queries
- ⚡ **Performance Optimized** - Efficient schema analysis and caching
- 📊 **Query Analysis** - Explains and validates generated queries

## Requirements

- PHP 7.4+
- Laravel 8.0+
- Composer
- OpenAI API key or Google Cloud credentials (for Gemini)

## Installation

1. Install via Composer:
   ```bash
   composer require laravelai/smartagent:dev-main
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

   ## Database Integration

### Schema-Aware Query Generation

The package automatically analyzes your database schema to generate accurate SQL queries. It understands:
- Table relationships (foreign keys)
- Column data types
- Primary keys
- Indexes
- Table aliases

### Working with JOINs

```php
// Natural language to complex JOIN query
$result = AiAgent::queryWithDatabaseContext(
    "Show me all posts with their author names and comments count"
);

// Generated SQL will automatically include proper JOINs:
/*
SELECT p.*, u.name as author, COUNT(c.id) as comments_count
FROM posts p
JOIN users u ON p.user_id = u.id
LEFT JOIN comments c ON p.id = c.post_id
GROUP BY p.id, u.name
*/
```

### Schema Inspection

Inspect your database schema programmatically:

```php
use LaravelAI\SmartAgent\Services\DatabaseSchemaService;

$schema = new DatabaseSchemaService();

// Get complete schema
$fullSchema = $schema->getSchema();

// Get specific table details
$usersTable = $schema->getTableSchema('users');

// Check table relationships
$relationships = $schema->getTableRelationships('posts');
```

## Basic Usage

```php
use LaravelAI\SmartAgent\Facades\AiAgent;

// Simple chat with a single message
$response = AiAgent::chat([
    [
        'role' => 'user',
        'content' => 'Hello, how are you?'
    ]
]);

// With memory
// First message
AiAgent::chat([
    [
        'role' => 'user',
        'content' => 'My name is John'
    ]
]);

// Second message that will have context from the first
$response = AiAgent::chat([
    [
        'role' => 'user',
        'content' => 'What is my name?'
    ]
]); // Will remember the name is John
```

### Advanced Query Generation

```php
// Natural language to SQL
$result = AiAgent::queryWithDatabaseContext(
    "Show me all active users who made a purchase in the last 30 days"
);

// Complex queries with JOINs
$result = AiAgent::queryWithDatabaseContext(
    "Find all customers who purchased more than 5 items in the last month, 
     along with their total spending, ordered by most recent purchase"
);

// Safe query execution with parameters
$users = AiAgent::executeSafeQuery(
    "SELECT u.*, COUNT(o.id) as order_count 
     FROM users u 
     LEFT JOIN orders o ON u.id = o.user_id 
     WHERE u.active = ? AND o.created_at > ? 
     GROUP BY u.id",
    [1, now()->subDays(30)]
);
```

#### Query Validation

All generated queries are validated against:
- SQL syntax
- Table and column existence
- JOIN conditions
- Potentially dangerous operations
- Foreign key relationships

## Configuration

Edit `config/ai-agents.php` for advanced configuration:

```php
return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default AI provider that will be used to
    | generate responses. Supported: "openai", "gemini"
    */
    'default' => env('AI_AGENT_DRIVER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Database Settings
    |--------------------------------------------------------------------------
    */
    'database' => [
        'auto_load_schema' => true,
        'exclude_tables' => [
            'migrations',
            'password_resets',
            'telescope_*',
            'failed_jobs',
            'jobs',
            'sessions',
        ],
        'cache_ttl' => 3600, // Cache schema for 1 hour
    ],
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
