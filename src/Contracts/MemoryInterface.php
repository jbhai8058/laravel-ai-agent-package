<?php
namespace LaravelAI\SmartAgent\Contracts;

interface MemoryInterface
{
    /**
     * Add a message to memory
     */
    public function addMessage(array $message): void;

    /**
     * Get all messages from memory
     */
    public function getMessages(): array;

    /**
     * Clear all messages from memory
     */
    public function clear(): void;
}
