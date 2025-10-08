<?php

namespace LaravelAI\SmartAgent\Drivers;

use LaravelAI\SmartAgent\Contracts\AiAgentInterface;
use OpenAI\Client as OpenAIClient;

class OpenaiDriver implements AiAgentInterface
{
    protected OpenAIClient $client;
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = \OpenAI::client($config['api_key']);
    }

    public function sendPrompt(string $prompt, array $options = []): string
    {
        $response = $this->client->chat()->create([
            'model' => $options['model'] ?? $this->config['default_model'] ?? 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => $options['temperature'] ?? 0.7,
            ...$options
        ]);

        return $response->choices[0]->message->content;
    }

    public function chat(array $messages, array $options = []): string
    {
        $response = $this->client->chat()->create([
            'model' => $options['model'] ?? $this->config['default_model'] ?? 'gpt-3.5-turbo',
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            ...$options
        ]);

        return $response->choices[0]->message->content;
    }

    public function embed(string $text): array
    {
        $response = $this->client->embeddings()->create([
            'model' => 'text-embedding-ada-002',
            'input' => $text,
        ]);

        return $response->embeddings[0]->embedding;
    }
}
