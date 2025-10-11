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
You are an expert SQL developer. Your task is to generate accurate and secure SQL queries based on the user's request.

# Database Schema
{$context}

# Instructions
1. Carefully analyze the request to understand the user's intent
2. Use ONLY tables and columns that exist in the provided schema
3. Generate valid, parameterized SQL for MySQL/MariaDB
4. Format your response with SQL in markdown code blocks
5. For multiple queries, separate them with semicolons

# Query Requirements
## For ALL Queries:
- Use parameterized queries with named parameters (e.g., :param_name)
- Include appropriate WHERE clauses to prevent unintended data access
- Add LIMIT clauses for SELECT queries (default 10 if not specified)
- Use table aliases for better readability
- Include relevant JOINs when querying related tables

## For Specific Query Types:
### SELECT:
- Only select necessary columns (avoid SELECT *)
- Include appropriate JOINs for related data
- Add WHERE clauses for filtering
- Include ORDER BY for sorting when relevant
- Always include LIMIT unless all results are explicitly needed

### INSERT:
- Include all required (NOT NULL) columns
- Use named parameters for values
- Handle auto-increment and default values appropriately
- Example: INSERT INTO table (col1, col2) VALUES (:val1, :val2)

### UPDATE:
- ALWAYS include a WHERE clause to prevent mass updates
- Use named parameters for both SET and WHERE clauses
- Include LIMIT 1 when updating a single record
- Example: UPDATE table SET col1 = :new_val WHERE id = :id LIMIT 1

### DELETE:
- ALWAYS include a WHERE clause to prevent mass deletion
- Use named parameters in WHERE clause
- Include LIMIT 1 when deleting a single record
- Consider adding a confirmation for large deletes
- Example: DELETE FROM table WHERE id = :id LIMIT 1

# Examples
## SELECT Example:
```sql
SELECT u.id, u.name, u.email, r.name as role_name
FROM users u
JOIN roles r ON u.role_id = r.id
WHERE u.status = :status
ORDER BY u.created_at DESC
LIMIT 10;
```

## INSERT Example:
```sql
INSERT INTO users (name, email, role_id, created_at)
VALUES (:name, :email, :role_id, NOW());
```

## UPDATE Example:
```sql
UPDATE users 
SET name = :name, email = :email, updated_at = NOW()
WHERE id = :id 
LIMIT 1;
```

## DELETE Example:
```sql
DELETE FROM users 
WHERE id = :id 
LIMIT 1;
```

IMPORTANT: Always generate complete, executable SQL statements with proper parameter binding.
PROMPT;

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt]
            ];
            
            $response = $this->aiAgent->chat($messages);
            
            // Clean up the response
            $response = trim($response);
            
            if (empty($response)) {
                throw new \RuntimeException('AI returned an empty response');
            }
            
            // Log the generated SQL for debugging
            \Log::debug('AI Query Generation', [
                'prompt' => $prompt,
                'raw_response' => $response,
                'context_summary' => substr($context, 0, 500) . (strlen($context) > 500 ? '...' : '')
            ]);
            
            // Try to extract SQL from markdown code blocks
            if (preg_match('/```(?:sql)?\s*([\s\S]*?)\s*```/i', $response, $matches)) {
                $response = trim($matches[1]);
            }
            
            // Basic validation of the generated SQL
            $queryType = strtoupper(trim(explode(' ', $response)[0] ?? ''));
            if (!in_array($queryType, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'])) {
                throw new \RuntimeException("Invalid query type: {$queryType}");
            }
            
            // Additional validation for UPDATE/DELETE queries
            if (in_array($queryType, ['UPDATE', 'DELETE']) && 
                !preg_match('/\bWHERE\b/i', $response)) {
                throw new \RuntimeException("$queryType query must include a WHERE clause");
            }
            
            return $response;
            
        } catch (\Exception $e) {
            \Log::error('AI query generation failed', [
                'error' => $e->getMessage(),
                'prompt' => $prompt,
                'context_summary' => substr($context, 0, 500) . (strlen($context) > 500 ? '...' : ''),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new \RuntimeException('AI query generation failed: ' . $e->getMessage());
        }
    }

    protected function processAiResponse(string $response, string $context): array
    {
        $result = [
            'queries' => [],
            'warnings' => [],
            'is_ai_generated' => true
        ];

        try {
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
            
            if (empty($result['queries'])) {
                throw new \RuntimeException('No valid SQL queries could be extracted from the AI response');
            }
            
            return $result;
            
        } catch (\Exception $e) {
            // If we hit any errors, log them and return empty result to trigger fallback
            \Log::warning('Error processing AI response', [
                'error' => $e->getMessage(),
                'response' => $response,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'queries' => [],
                'warnings' => ['Error processing AI response: ' . $e->getMessage()],
                'is_ai_generated' => false
            ];
        }
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
    
    protected function buildSelectQuery(string $prompt, array $tables): string
    {
        if (empty($tables)) {
            throw new \RuntimeException('No tables available to build query');
        }
        
        $tableName = array_key_first($tables);
        $table = $tables[$tableName];
        $columns = [];
        $where = [];
        
        // Get all column names for the table
        $columnNames = array_keys($table['columns']);
        
        // Check if specific columns are mentioned in the prompt
        foreach ($columnNames as $column) {
            if (str_contains(strtolower($prompt), strtolower($column))) {
                $columns[] = $column;
                $where[] = "{$column} = :{$column}";
            }
        }
        
        // If no specific columns mentioned, select all
        $selectClause = !empty($columns) ? implode(', ', $columns) : '*';
        
        // Build the query
        $query = "SELECT {$selectClause} FROM {$tableName}";
        
        // Add WHERE clause if we have conditions
        if (!empty($where)) {
            $query .= " WHERE " . implode(' AND ', $where);
        }
        
        // Add ORDER BY if needed
        if (str_contains(strtolower($prompt), 'latest') || 
            str_contains(strtolower($prompt), 'newest') ||
            str_contains(strtolower($prompt), 'recent')) {
                
            // Look for created_at, updated_at, or date columns
            $dateColumns = ['created_at', 'updated_at', 'date', 'timestamp'];
            $orderColumn = null;
            
            foreach ($dateColumns as $col) {
                if (in_array($col, $columnNames)) {
                    $orderColumn = $col;
                    break;
                }
            }
            
            if ($orderColumn) {
                $query .= " ORDER BY {$orderColumn} DESC";
            }
        }
        
        // Add LIMIT for safety
        if (!str_contains(strtolower($prompt), 'all ')) {
            $query .= " LIMIT 10";
        }
        
        return $query;
    }
    
    protected function buildInsertQuery(string $prompt, array $tables): string
    {
        if (empty($tables)) {
            throw new \RuntimeException('No tables available to build query');
        }
        
        $tableName = array_key_first($tables);
        $table = $tables[$tableName];
        $columns = [];
        $values = [];
        
        // Look for column-value pairs in the prompt
        foreach ($table['columns'] as $column => $details) {
            // Skip auto-increment or timestamp columns
            if (in_array($column, ['id', 'created_at', 'updated_at'])) {
                continue;
            }
            
            // Look for the column name in the prompt
            if (str_contains(strtolower($prompt), strtolower($column))) {
                $columns[] = $column;
                $values[] = ":{$column}";
            }
        }
        
        if (empty($columns)) {
            throw new \RuntimeException('No valid columns found for INSERT query');
        }
        
        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $tableName,
            implode(', ', $columns),
            implode(', ', $values)
        );
    }
    
    protected function buildUpdateQuery(string $prompt, array $tables): string
    {
        if (empty($tables)) {
            throw new \RuntimeException('No tables available to build query');
        }
        
        $tableName = array_key_first($tables);
        $table = $tables[$tableName];
        $updates = [];
        $where = [];
        
        // Look for column-value pairs in the prompt
        foreach ($table['columns'] as $column => $details) {
            // Skip primary key and timestamp columns
            if (in_array($column, ['id', 'created_at', 'updated_at'])) {
                continue;
            }
            
            // Look for the column name in the prompt
            if (str_contains(strtolower($prompt), strtolower($column))) {
                $updates[] = "{$column} = :update_{$column}";
            }
        }
        
        // Add WHERE clause for primary key if available
        if (!empty($table['primary_key'])) {
            foreach ($table['primary_key'] as $pk) {
                $where[] = "{$pk} = :where_{$pk}";
            }
        } else {
            // Fallback to ID column if no primary key defined
            $where[] = "id = :where_id";
        }
        
        if (empty($updates)) {
            throw new \RuntimeException('No valid columns found for UPDATE query');
        }
        
        return sprintf(
            'UPDATE %s SET %s WHERE %s',
            $tableName,
            implode(', ', $updates),
            implode(' AND ', $where)
        );
    }
    
    protected function buildDeleteQuery(string $prompt, array $tables): string
    {
        if (empty($tables)) {
            throw new \RuntimeException('No tables available to build query');
        }
        
        $tableName = array_key_first($tables);
        $table = $tables[$tableName];
        $where = [];
        
        // Add WHERE clause for primary key if available
        if (!empty($table['primary_key'])) {
            foreach ($table['primary_key'] as $pk) {
                if (str_contains(strtolower($prompt), strtolower($pk))) {
                    $where[] = "{$pk} = :{$pk}";
                }
            }
        }
        
        // If no primary key conditions, add a safe guard to prevent mass deletion
        if (empty($where)) {
            throw new \RuntimeException('Cannot build DELETE query without primary key condition');
        }
        
        return 'DELETE FROM ' . $tableName . ' WHERE ' . implode(' AND ', $where);
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
        $context = "# Database Schema\n\n";
        
        if (empty($tables)) {
            throw new \RuntimeException('No tables found in schema');
        }
        
        foreach ($tables as $tableName => $tableInfo) {
            if (!is_array($tableInfo) || empty($tableInfo)) {
                continue;
            }
            
            $context .= "## Table: `{$tableName}`\n\n";
            
            // Handle columns
            $columns = $tableInfo['columns'] ?? [];
            if (empty($columns)) {
                $context .= "*No columns found*\n\n";
                continue;
            }
            
            $context .= "### Columns\n";
            foreach ($columns as $column => $details) {
                if (!is_array($details)) {
                    $context .= "- `{$column}`: Unknown type\n";
                    continue;
                }
                
                $type = $details['type'] ?? 'unknown';
                $constraints = [];
                
                if (!empty($details['nullable'])) {
                    $constraints[] = 'NULL';
                } else {
                    $constraints[] = 'NOT NULL';
                }
                
                if (isset($details['default'])) {
                    $default = is_string($details['default']) 
                        ? "'{$details['default']}'" 
                        : (string)$details['default'];
                    $constraints[] = "DEFAULT {$default}";
                }
                
                if (!empty($details['key']) && $details['key'] === 'PRI') {
                    $constraints[] = 'PRIMARY KEY';
                }
                
                if (!empty($details['extra']) && str_contains(strtoupper($details['extra']), 'AUTO_INCREMENT')) {
                    $constraints[] = 'AUTO_INCREMENT';
                }
                
                $context .= sprintf(
                    "- `%s`: %s %s\n",
                    $column,
                    strtoupper($type),
                    implode(' ', $constraints)
                );
            }
            
            // Add primary key info
            if (!empty($tableInfo['primary_key'])) {
                $context .= "\n### Primary Key\n";
                $context .= "- " . implode(', ', (array)$tableInfo['primary_key']) . "\n";
            }
            
            // Add foreign key info
            if (!empty($tableInfo['foreign_keys'])) {
                $context .= "\n### Foreign Keys\n";
                foreach ((array)$tableInfo['foreign_keys'] as $fk) {
                    if (is_array($fk) && isset($fk['column'], $fk['foreign_table'], $fk['foreign_column'])) {
                        $context .= sprintf(
                            "- `%s` → `%s`.`%s`\n",
                            $fk['column'],
                            $fk['foreign_table'],
                            $fk['foreign_column']
                        );
                    }
                }
            }
            
            // Add indexes
            if (!empty($tableInfo['indexes'])) {
                $context .= "\n### Indexes\n";
                foreach ((array)$tableInfo['indexes'] as $indexName => $index) {
                    $columns = is_array($index['columns'] ?? null) 
                        ? implode('`, `', $index['columns'])
                        : 'unknown';
                    $type = !empty($index['unique']) ? 'UNIQUE ' : '';
                    $context .= "- {$type}INDEX `{$indexName}` (`{$columns}`)\n";
                }
            }
            
            $context .= "\n---\n\n";
        }
        
        // Add query generation instructions
        $context .= "# Query Generation Guidelines\n\n";
        $context .= "1. Always use proper JOIN syntax when querying related tables\n";
        $context .= "2. Prefer INNER JOIN for required relationships, LEFT JOIN for optional ones\n";
        $context .= "3. Include only necessary columns in SELECT statements\n";
        $context .= "4. Add appropriate WHERE conditions for filtering\n";
        $context .= "5. Include ORDER BY for sorting when relevant\n";
        $context .= "6. Use LIMIT for pagination or to prevent excessive results\n";
        $context .= "7. For complex queries, consider using subqueries or CTEs if supported\n";
        
        return $context;
    }
    
    /**
     * Enhanced method to get relevant tables with better schema handling
     */
    /**
     * Get relevant tables based on the prompt
     *
     * @param string $prompt The user's query prompt
     * @param array $tables Specific tables to consider (optional)
     * @return array Array of relevant tables with their schema
     * @throws \RuntimeException If schema is empty or invalid
     */
    protected function getRelevantTables(string $prompt, array $tables = []): array
    {
        if (empty($this->schema)) {
            throw new \RuntimeException('Database schema is empty');
        }
        
        $prompt = strtolower($prompt);
        $relevantTables = [];
        
        // If specific tables are requested, use those
        if (!empty($tables)) {
            return array_intersect_key($this->schema, array_flip($tables));
        }
        
        // First pass: look for exact table name matches
        foreach ($this->schema as $tableName => $tableInfo) {
            if (!is_array($tableInfo)) {
                continue;
            }
            
            $singularTable = Str::singular($tableName);
            
            // Check for exact table name match
            if (preg_match('/\b' . preg_quote($tableName, '/') . '\b/i', $prompt) ||
                preg_match('/\b' . preg_quote($singularTable, '/') . '\b/i', $prompt)) {
                $relevantTables[$tableName] = $tableInfo;
            }
        }
        
        // If we found tables by name, return them
        if (!empty($relevantTables)) {
            return $relevantTables;
        }
        
        // Second pass: look for column name matches
        foreach ($this->schema as $tableName => $tableInfo) {
            if (!is_array($tableInfo) || empty($tableInfo['columns']) || !is_array($tableInfo['columns'])) {
                continue;
            }
            
            foreach ($tableInfo['columns'] as $column => $details) {
                if (preg_match('/\b' . preg_quote($column, '/') . '\b/i', $prompt)) {
                    $relevantTables[$tableName] = $tableInfo;
                    break;
                }
            }
        }
        
        // If still no tables found, return all tables as fallback
        return !empty($relevantTables) ? $relevantTables : $this->schema;
    }
}
