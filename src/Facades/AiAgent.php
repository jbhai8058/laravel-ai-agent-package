<?php

namespace MuhammadJunaidRehmanSiddiqui\AiAgents\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \MuhammadJunaidRehmanSiddiqui\AiAgents\AiAgentManager driver(string $driver = null)
 * @method static string sendPrompt(string $prompt, array $options = [])
 * @method static string chat(array $messages, array $options = [])
 * @method static array embed(string $text)
 */
class AiAgent extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'ai-agents';
    }
}
