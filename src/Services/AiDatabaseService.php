<?php

namespace LaravelAI\SmartAgent\Services;

use LaravelAI\SmartAgent\Contracts\AiAgentInterface;

class AiDatabaseService
{
    protected QuerySuggestionService $querySuggestionService;
    protected QueryExecutionService $queryExecutionService;
    protected array $schema;
    
    public function __construct(array $schema, AiAgentInterface $aiAgent)
    {
        $this->schema = $schema;
        $this->querySuggestionService = new QuerySuggestionService(
            $schema, 
            $aiAgent ?? app(AiAgentInterface::class)
        );
        $this->queryExecutionService = new QueryExecutionService();
    }
    
    /**
     * Get query suggestions based on natural language input
     */
    public function queryWithContext(string $prompt, array $tables = []): array
    {
        return $this->querySuggestionService->suggestQueries($prompt, $tables);
    }
    
    /**
     * Execute a safe SQL query (SELECT only for security)
     */
    public function executeSafeQuery(string $query, array $bindings = [])
    {
        return $this->queryExecutionService->executeSafeQuery($query, $bindings);
    }
    
    /**
     * Check if a query is safe to execute
     */
    public function isQuerySafe(string $query): bool
    {
        return $this->queryExecutionService->validateQuery($query);
    }
}
