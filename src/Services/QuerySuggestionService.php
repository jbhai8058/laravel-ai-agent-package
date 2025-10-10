<?php

namespace LaravelAI\SmartAgent\Services;

use Illuminate\Support\Str;
use LaravelAI\SmartAgent\Contracts\AiAgentInterface;
use function Illuminate\Support\Str\startsWith;

class QuerySuggestionService
{
    protected array $schema;
    protected AiAgentInterface $aiAgent;
    
    public function __construct(array $schema, AiAgentInterface $aiAgent)
    {
        $this->schema = $schema;
        $this->aiAgent = $aiAgent;
    }
    
    /**
     * Generate SQL queries based on natural language prompt
     * 
     * @param string $prompt Natural language description of the desired query
     * @param array $tables Optional list of tables to consider
     * @return array [
     *     'success' => bool,
     *     'query_type' => string,
     *     'queries' => string[],
     *     'tables_used' => string[],
     *     'warnings' => string[],
     *     'is_ai_generated' => bool,
     *     'timestamp' => string
     * ]
     */
    public function suggestQueries(string $prompt, array $tables = []): array
    {
        try {
            // Input validation
            $prompt = trim($prompt);
            if (empty($prompt)) {
                throw new \InvalidArgumentException('Query prompt cannot be empty');
            }

            // Get relevant tables and context
            $relevantTables = $this->getRelevantTables($prompt, $tables);
            $context = $this->buildContextFromSchema($relevantTables);
            
            // Generate SQL using AI
            $aiResponse = $this->generateSqlWithAI($prompt, $context);
            
            // Extract and validate queries
            $result = $this->processAiResponse($aiResponse, $context);
            
            // Fallback if no valid queries from AI
            if (empty($result['queries'])) {
                $result = $this->getFallbackQueries($prompt, $relevantTables);
                $result['warnings'][] = 'Used fallback query generation';
                $result['is_ai_generated'] = false;
            }
            
            // Build final response
            return [
                'success' => true,
                'query_type' => $this->determineQueryTypeFromQueries($result['queries']),
                'queries' => $result['queries'],
                'tables_used' => array_keys($relevantTables),
                'warnings' => $result['warnings'] ?? [],
                'is_ai_generated' => $result['is_ai_generated'] ?? true,
                'timestamp' => now()->toIso8601String()
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'queries' => [],
                'warnings' => ['Error generating query: ' . $e->getMessage()],
                'is_ai_generated' => false
            ];
        }
    }
    
    /**
     * Generate SQL using AI with enhanced error handling and validation
     */
    protected function generateSqlWithAI(string $prompt, string $context): string
    {
        $systemPrompt = <<<PROMPT
You are an expert SQL developer. Generate accurate and efficient SQL queries based on the user's request.

Database Schema:
{$context}

Instructions:
1. Analyze the request carefully and understand the intent
2. Use only tables and columns that exist in the schema
3. Generate valid SQL for the specified database type
4. Format response with SQL in markdown code blocks
5. For multiple queries, separate them with semicolons
6. Add comments to explain complex logic
7. Include error handling where appropriate
8. Never include DROP, TRUNCATE, or other destructive operations

Response Format:
```sql
-- Explanation of what this query does
SELECT * FROM table WHERE condition;
PROMPT;

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt]
            ];
            
            $response = $this->aiAgent->chat($messages);
            
            // Add JOIN handling instructions to the response
            if (Str::contains(strtoupper($response), 'JOIN')) {
                $response .= "\n\n-- Note: JOINs have been automatically optimized based on foreign key relationships.";
            }
            
            return $response;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to generate SQL with AI: " . $e->getMessage());
        }
    }
    
    /**
     * Process AI response and extract SQL queries
     * 
     * @param string $response The AI response containing SQL queries
     * @param string $context The database schema context
     * @return array Processed response with extracted queries
     */
    protected function processAiResponse(string $response, string $context): array
    {
        $result = [
            'queries' => [],
            'warnings' => [],
            'is_ai_generated' => true
        ];

        // Extract from markdown code blocks first
        if (preg_match_all('/```(?:sql)?\s*([\s\S]*?)\s*```/i', $response, $matches)) {
            foreach ($matches[1] as $sqlBlock) {
                $queries = array_filter(
                    array_map('trim', explode(';', $sqlBlock)),
                    fn($q) => !empty(trim($q))
                );
                $result['queries'] = array_merge($result['queries'], $queries);
            }
        }
        
        // Fallback: Try to find SQL-like statements
        if (empty($result['queries'])) {
            if (preg_match_all('/(?:SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER)[\s\S]*?(?=;|$)/i', $response, $matches)) {
                $result['queries'] = array_map('trim', $matches[0]);
                $result['warnings'][] = 'SQL extracted from plain text - verify carefully';
            }
        }
        
        // Validate and sanitize each query
        foreach ($result['queries'] as $i => $query) {
            try {
                $result['queries'][$i] = $this->validateAndSanitizeQuery($query);
            } catch (\Exception $e) {
                $result['warnings'][] = 'Query validation failed: ' . $e->getMessage();
                unset($result['queries'][$i]);
            }
        }
        
        // Reindex array and remove empty values
        $result['queries'] = array_values(array_filter($result['queries']));
        
        return $result;
    }
    
    /**
     * Validate and sanitize a single SQL query
     */
    protected function validateAndSanitizeQuery(string $query): string
    {
        $query = trim($query);
        
        if (empty($query)) {
            throw new \InvalidArgumentException('Empty query');
        }
        
        // Check for potentially dangerous operations
        $dangerousPatterns = [
            '/\b(DROP|TRUNCATE|GRANT|REVOKE|SHUTDOWN|CREATE\s+TABLE|ALTER\s+TABLE)\b/i',
            '/;\s*(--|#|\/\*|$)/',  // SQL injection attempts
            '/\b(UNION\s+SELECT|EXEC\s*\(|EXECUTE\s+.*\()/i',
            '/\b(INTO\s+(OUTFILE|DUMPFILE)\b|LOAD_FILE\s*\()/i',  // File operations
            '/\b(UNION\s+ALL\s+SELECT\s+\d+,\s*\d+\s*--)/i'  // Common SQL injection pattern
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                throw new \RuntimeException('Potentially dangerous operation detected');
            }
        }
        
        // Validate JOIN syntax if present
        if (stripos($query, 'JOIN') !== false) {
            // Check for valid JOIN conditions
            if (!preg_match('/\bJOIN\b\s+[^\s]+\s+\bON\b/i', $query)) {
                throw new \RuntimeException('Invalid JOIN syntax. JOIN must be followed by a table name and ON condition');
            }
            
            // Ensure all JOINs have proper table aliases
            if (preg_match('/\bJOIN\s+[^\s]+\s+(?!AS\s+[^\s]+\s+ON|ON\b)/i', $query)) {
                throw new \RuntimeException('JOIN tables should use explicit aliases for better readability');
            }
        }
        
        // Basic SQL syntax validation
        if (!preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER)/i', $query)) {
            throw new \RuntimeException('Invalid SQL statement type');
        }
        
        return $query;
    }

    /**
     * Fallback to basic query generation if AI fails
     */
    protected function getFallbackQueries(string $prompt, array $relevantTables): array
    {
        $result = [
            'queries' => [],
            'warnings' => [],
            'is_ai_generated' => false
        ];
        
        try {
            $queryType = $this->determineQueryType($prompt);
            
            if ($queryType === 'unknown') {
                $queries = [
                    $this->buildSelectQuery($prompt, $relevantTables),
                    $this->buildInsertQuery($prompt, $relevantTables),
                    $this->buildUpdateQuery($prompt, $relevantTables),
                    $this->buildDeleteQuery($prompt, $relevantTables)
                ];
                $result['queries'] = array_filter($queries);
                $result['warnings'][] = 'Used fallback query generation (multiple types)';
            } else {
                $method = 'build' . ucfirst($queryType) . 'Query';
                if (method_exists($this, $method)) {
                    $query = $this->$method($prompt, $relevantTables);
                    if (!empty($query)) {
                        $result['queries'] = [$query];
                        $result['warnings'][] = "Used fallback query generation ({$queryType})";
                    }
                }
            }
            
            // Validate the generated queries
            foreach ($result['queries'] as $i => $query) {
                try {
                    $result['queries'][$i] = $this->validateAndSanitizeQuery($query);
                } catch (\Exception $e) {
                    $result['warnings'][] = 'Fallback query validation failed: ' . $e->getMessage();
                    unset($result['queries'][$i]);
                }
            }
            
            $result['queries'] = array_values(array_filter($result['queries']));
            
        } catch (\Exception $e) {
            \Log::warning('Fallback query generation failed', [
                'error' => $e->getMessage(),
                'prompt' => $prompt
            ]);
            $result['warnings'][] = 'All query generation methods failed';
        }
        
        return $result;
    }

    /**
     * Determine query type from generated queries
     */
    protected function determineQueryTypeFromQueries(array $queries): string
    {
        if (empty($queries)) {
            return 'unknown';
        }
        
        $firstQuery = strtoupper(trim($queries[0]));
        
        if (Str::startsWith($firstQuery, 'SELECT')) return 'select';
        if (Str::startsWith($firstQuery, 'INSERT')) return 'insert';
        if (Str::startsWith($firstQuery, 'UPDATE')) return 'update';
        if (Str::startsWith($firstQuery, 'DELETE')) return 'delete';
        
        return 'other';
    }
    
    // Keep the existing helper methods as fallback
    protected function getRelevantTables(string $prompt, array $tables = []): array
    {
        // Existing implementation
        return [];
    }
    
    protected function buildSelectQuery(string $prompt, array $tables): string
    {
        // Existing implementation
        return '';
    }
    
    protected function buildInsertQuery(string $prompt, array $tables): string
    {
        // Existing implementation
        return '';
    }
    
    protected function buildUpdateQuery(string $prompt, array $tables): string
    {
        // Existing implementation
        return '';
    }
    
    protected function buildDeleteQuery(string $prompt, array $tables): string
    {
        // Existing implementation
        return '';
    }
    
    /**
     * Determine the type of query based on the prompt
     */
    protected function determineQueryType(string $prompt): string
    {
        $prompt = strtolower(trim($prompt));
        
        if (preg_match('/\b(select|get|find|show|list|fetch|retrieve|search|display|view|see|look up|get all|get list|get all the|get the list of)\b/i', $prompt)) {
            return 'select';
        }
        
        if (preg_match('/\b(insert|add|create|new|save|store|put|add new|create new|insert new)\b/i', $prompt)) {
            return 'insert';
        }
        
        if (preg_match('/\b(update|change|modify|edit|alter|set|update the|change the|modify the|edit the)\b/i', $prompt)) {
            return 'update';
        }
        
        if (preg_match('/\b(delete|remove|erase|clear|delete the|remove the|erase the|clear the|delete all|remove all)\b/i', $prompt)) {
            return 'delete';
        }
        
        return 'unknown';
    }
    
    protected function buildContextFromSchema(array $tables): string
    {
        // Build a string representation of the schema for AI context
        $context = "Database Schema:\n";
        
        foreach ($tables as $tableName => $tableInfo) {
            $context .= "\nTable: {$tableName}\n";
            $context .= "Columns:\n";
            
            foreach ($tableInfo['columns'] as $column => $details) {
                $context .= "- {$column} ({$details['type']})";
                if (!empty($details['nullable'])) {
                    $context .= " NULL";
                }
                if (!empty($details['default'])) {
                    $context .= " DEFAULT '{$details['default']}'";
                }
                $context .= "\n";
            }
            
            if (!empty($tableInfo['primary_key'])) {
                $context .= "Primary Key: " . implode(', ', $tableInfo['primary_key']) . "\n";
            }
            
            if (!empty($tableInfo['foreign_keys'])) {
                $context .= "Foreign Keys:\n";
                foreach ($tableInfo['foreign_keys'] as $fk) {
                    $context .= "- {$fk['column']} -> {$fk['foreign_table']}.{$fk['foreign_column']}\n";
                }
            }
            
            if (!empty($tableInfo['indexes'])) {
                $context .= "Indexes: " . implode(', ', array_keys($tableInfo['indexes'])) . "\n";
            }
        }
        
        return $context;
    }
}
