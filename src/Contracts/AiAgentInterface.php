<?php

namespace LaravelAI\SmartAgent\Contracts;

interface AiAgentInterface
{
    /**
     * Send a prompt to the AI service and get the response
     */
    public function sendPrompt(string $prompt, array $options = []): string;

    /**
     * Send a chat message and get the response
     */
    public function chat(array $messages, array $options = []): string;

    /**
     * Generate embeddings for the given text
     */
    public function embed(string $text): array;
}
