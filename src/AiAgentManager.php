<?php

namespace LaravelAI\SmartAgent;

use Illuminate\Support\Facades\Config;
use LaravelAI\SmartAgent\Contracts\AiAgentInterface;
use LaravelAI\SmartAgent\Contracts\MemoryInterface;
use LaravelAI\SmartAgent\Memory\BufferMemory;
use LaravelAI\SmartAgent\Services\DatabaseSchemaService;
use LaravelAI\SmartAgent\Services\AiDatabaseService;
use LaravelAI\SmartAgent\Exceptions\UnsupportedAiProviderException;

class AiAgentManager
{
    protected array $drivers = [];
    protected array $memories = [];
    protected ?string $defaultModel = null;
    protected ?array $databaseSchema = null;
    protected ?AiDatabaseService $databaseService = null;

    public function __construct()
    {
        $this->defaultModel = config('ai-agents.default_model');
        $this->loadDatabaseSchema();
    }
    
    protected function loadDatabaseSchema(): void
    {
        try {
            if (config('ai-agents.database.auto_load_schema', true)) {
                $schemaService = new DatabaseSchemaService();
                $this->databaseSchema = $schemaService->getSchema();
                $this->databaseService = new AiDatabaseService($this->databaseSchema);
            }
        } catch (\Exception $e) {
            // Log error but don't break the application
            logger()->error('Failed to load database schema: ' . $e->getMessage());
        }
    }
    
    public function getDatabaseSchema(): ?array
    {
        return $this->databaseSchema;
    }
    
    public function getTableSchema(string $table): ?array
    {
        return $this->databaseSchema[$table] ?? null;
    }
    
    public function queryWithDatabaseContext(string $prompt, array $tables = []): array
    {
        if (!$this->databaseService) {
            throw new \RuntimeException('Database service is not initialized. Make sure database schema loading is enabled.');
        }
        
        return $this->databaseService->queryWithContext($prompt, $tables);
    }
    
    public function executeSafeQuery(string $query, array $bindings = [])
    {
        if (!$this->databaseService) {
            throw new \RuntimeException('Database service is not initialized. Make sure database schema loading is enabled.');
        }
        
        return $this->databaseService->executeSafeQuery($query, $bindings);
    }

    public function driver(string $driver = null): self
    {
        $driver = $this->normalizeDriverName($driver);

        if (!isset($this->drivers[$driver])) {
            $this->drivers[$driver] = $this->createDriver($driver);
            $this->initializeMemory($driver);
        }

        return $this;
    }

    public function model(string $model): self
    {
        $this->defaultModel = $model;
        return $this;
    }

    public function memory(string $driver = null): MemoryInterface
    {
        $driver = $this->normalizeDriverName($driver);
        return $this->memories[$driver] ?? $this->initializeMemory($driver);
    }

    protected function createDriver(string $driver): AiAgentInterface
    {
        $config = $this->getConfig($driver);
        $driverClass = $this->getDriverClass($driver);

        if (!class_exists($driverClass)) {
            throw new UnsupportedAiProviderException("Driver [{$driver}] is not supported.");
        }

        return new $driverClass($config);
    }

    protected function getDriverClass(string $driver): string
    {
        return __NAMESPACE__ . '\\Drivers\\' . ucfirst($driver) . 'Driver';
    }

    protected function getConfig(string $driver): array
    {
        $config = Config::get("ai-agents.providers.{$driver}", []);
        
        // Override model if set via method
        if ($this->defaultModel) {
            $config['default_model'] = $this->defaultModel;
        }
        
        return $config;
    }
    
    protected function normalizeDriverName(?string $driver): string
    {
        return $driver ?: $this->getDefaultDriver();
    }
    
    protected function initializeMemory(string $driver): MemoryInterface
    {
        $memoryConfig = config('ai-agents.memory', [
            'max_size' => 10,
            'enabled' => true
        ]);

        $this->memories[$driver] = new BufferMemory($memoryConfig['max_size']);
        return $this->memories[$driver];
    }

    public function getDefaultDriver(): string
    {
        return Config::get('ai-agents.default');
    }

    public function __call($method, $parameters)
    {
        $driver = $this->getDefaultDriver();
        $instance = $this->driver($driver);
        
        // Check if the method exists on the driver
        if (method_exists($instance, $method)) {
            return $instance->$method(...$parameters);
        }
        
        // If it's a chat method, handle memory
        if ($method === 'chat') {
            return $this->handleChat($parameters[0], $parameters[1] ?? []);
        }
        
        throw new \BadMethodCallException("Method {$method} does not exist.");
    }
    
    protected function handleChat(array $messages, array $options = []): string
    {
        $driver = $this->getDefaultDriver();
        $memory = $this->memory($driver);
        
        // Add new messages to memory
        foreach ($messages as $message) {
            $memory->addMessage($message);
        }
        
        // Get all messages from memory
        $context = $memory->getMessages();
        
        // Add database schema context if available
        $schemaContext = $this->getSchemaContextForPrompt($messages);
        if ($schemaContext) {
            $context = $this->addSchemaContextToMessages($context, $schemaContext);
        }
        
        // Call the actual driver's chat method
        return $this->drivers[$driver]->chat($context, $options);
    }
    
    protected function getSchemaContextForPrompt(array $messages): ?string
    {
        if (empty($this->databaseSchema)) {
            return null;
        }
        
        // Check if the prompt is related to database operations
        $lastMessage = end($messages)['content'] ?? '';
        $databaseKeywords = ['table', 'database', 'schema', 'column', 'select', 'insert', 'update', 'delete', 'where'];
        
        $hasDatabaseContext = false;
        foreach ($databaseKeywords as $keyword) {
            if (stripos($lastMessage, $keyword) !== false) {
                $hasDatabaseContext = true;
                break;
            }
        }
        
        if (!$hasDatabaseContext) {
            return null;
        }
        
        // Prepare schema context
        $schemaInfo = [];
        foreach ($this->databaseSchema as $tableName => $tableInfo) {
            $columns = array_map(function($column) use ($tableInfo) {
                $columnInfo = "- {$column}: {$tableInfo['columns'][$column]['type']}";
                if (isset($tableInfo['foreign_keys'][$column])) {
                    $fk = $tableInfo['foreign_keys'][$column];
                    $columnInfo .= " (references {$fk['foreign_table']}.{$fk['foreign_column']})";
                }
                return $columnInfo;
            }, array_keys($tableInfo['columns']));
            
            $schemaInfo[] = "Table: {$tableName}\n" . implode("\n", $columns);
        }
        
        return "Database Schema:\n" . implode("\n\n", $schemaInfo);
    }
    
    protected function addSchemaContextToMessages(array $messages, string $schemaContext): array
    {
        // Add schema context as a system message at the beginning
        array_unshift($messages, [
            'role' => 'system',
            'content' => "You are an AI assistant with access to the following database schema. " .
                        "Use this information to provide accurate and relevant responses.\n\n" . $schemaContext
        ]);
        
        return $messages;
    }
}
