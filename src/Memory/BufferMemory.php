<?php
namespace LaravelAI\SmartAgent\Memory;

use LaravelAI\SmartAgent\Contracts\MemoryInterface;

class BufferMemory implements MemoryInterface
{
    protected array $messages = [];
    protected int   $maxSize;

    public function __construct(int $maxSize = 10)
    {
        $this->maxSize = $maxSize;
    }

    public function addMessage(array $message): void
    {
        $this->messages[] = $message;
        $this->trimToMaxSize();
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    protected function trimToMaxSize(): void
    {
        while (count($this->messages) > $this->maxSize) {
            array_shift($this->messages);
        }
    }
}
