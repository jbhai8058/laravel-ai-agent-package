<?php
namespace LaravelAI\SmartAgent\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \LaravelAI\SmartAgent\AiAgentManager driver(string $driver = null)
 * @method static string sendPrompt(string $prompt, array $options = [])
 * @method static string chat(array $messages, array $options = [])
 * @method static array embed(string $text)
 * @method static array queryWithDatabaseContext(string $prompt, array $tables = [])
 * @method static mixed executeSafeQuery(string $query, array $bindings = [])
 */
class AiAgent extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'ai-agents';
    }
}
