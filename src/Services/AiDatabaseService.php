<?php

namespace LaravelAI\SmartAgent\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiDatabaseService
{
    protected array $schema;
    
    public function __construct(array $schema)
    {
        $this->schema = $schema;
    }
    
    public function queryWithContext(string $prompt, array $tables = []): array
    {
        try {
            // Get relevant tables based on the prompt
            $relevantTables = $this->getRelevantTables($prompt, $tables);
            
            // Build context from schema
            $context = $this->buildContextFromSchema($relevantTables);
            
            // Determine the type of query needed
            $queryType = $this->determineQueryType($prompt);
            $generatedQuery = null;
            
            // Generate the appropriate query based on the type
            switch ($queryType) {
                case 'select':
                    $generatedQuery = $this->buildSelectQuery($prompt, $relevantTables);
                    break;
                    
                case 'insert':
                    $generatedQuery = $this->buildInsertQuery($prompt, $relevantTables);
                    break;
                    
                case 'update':
                    $generatedQuery = $this->buildUpdateQuery($prompt, $relevantTables);
                    break;
                    
                case 'delete':
                    $generatedQuery = $this->buildDeleteQuery($prompt, $relevantTables);
                    break;
                    
                case 'create_table':
                    $generatedQuery = $this->buildCreateTableQuery($prompt);
                    break;
                    
                default:
                    // If no specific type matched, return all possible queries
                    $suggestedQueries = $this->suggestQueries($prompt, $relevantTables);
                    $generatedQuery = !empty($suggestedQueries) ? $suggestedQueries[0] : null;
            }
            
            return [
                'success' => true,
                'query_type' => $queryType,
                'generated_query' => $generatedQuery,
                'context' => $context,
                'suggested_queries' => $this->suggestQueries($prompt, $relevantTables),
                'tables_used' => array_keys($relevantTables)
            ];
            
        } catch (\Exception $e) {
            \Log::error('Error in queryWithContext: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error processing your request: ' . $e->getMessage(),
                'context' => $context ?? 'No context available',
                'suggested_queries' => []
            ];
        }
    }
    
    protected function determineQueryType(string $prompt): string
    {
        $prompt = strtolower(trim($prompt));
        
        // Common phrases in different languages for each query type
        $phrases = [
            'create_table' => ['create table', 'new table', 'make table', 'table banao', 'banayein', 'naya table'],
            'insert' => ['insert', 'add', 'create', 'new', 'naya record', 'shamil karein', 'daal dein'],
            'update' => ['update', 'change', 'modify', 'edit', 'badal dein', 'tabdeel karein'],
            'delete' => ['delete', 'remove', 'erase', 'drop', 'hata dein', 'mita dein']
        ];
        
        foreach ($phrases as $type => $keywords) {
            if (Str::contains($prompt, $keywords)) {
                // Additional checks for specific types
                if ($type === 'insert' && Str::contains($prompt, ['table', 'jadwal'])) {
                    continue; // Skip if it's likely a create table request
                }
                return $type;
            }
        }
        
        // Check for select patterns (questions, show, list, etc.)
        if (preg_match('/(show|list|get|find|dikhao|kya|kaun|kab|kahan|kaise)/i', $prompt)) {
            return 'select';
        }
        
        // Default to select as it's the safest option
        return 'select';
    }
    
    protected function buildCreateTableQuery(string $prompt): string
    {
        // Extract table name and columns from prompt (simplified example)
        // In a real implementation, you'd use NLP to extract these details
        return "-- CREATE TABLE query would be generated here based on: " . $prompt . "\n" .
               "-- Example: CREATE TABLE table_name (\n" .
               "--     id INT AUTO_INCREMENT PRIMARY KEY,\n" .
               "--     column1 VARCHAR(255),\n" .
               "--     column2 INT\n" .
               "-- );";
    }
    
    protected function shouldInsertData(string $prompt, array $tables): bool
    {
        $prompt = strtolower($prompt);
        $insertKeywords = ['insert', 'add', 'create', 'new'];
        
        foreach ($insertKeywords as $keyword) {
            if (str_contains($prompt, $keyword)) {
                return true;
            }
        }
        
        return false;
    }
    
    protected function handleDataInsertion(string $prompt, array $tables): ?string
    {
        try {
            $table = array_key_first($tables);
            if (!$table) {
                return null;
            }
            
            // For demo purposes - in a real scenario, you'd extract data from the prompt
            // and build the appropriate insert query
            return "-- Data insertion would be handled here based on the prompt: " . $prompt;
            
        } catch (\Exception $e) {
            \Log::error('Error handling data insertion: ' . $e->getMessage());
            return "-- Error: " . $e->getMessage();
        }
    }
    
    protected function getRelevantTables(string $prompt, array $tables = []): array
    {
        if (!empty($tables)) {
            return array_intersect_key($this->schema, array_flip($tables));
        }
        
        // Try to find relevant tables based on prompt
        $relevantTables = [];
        $prompt = strtolower($prompt);
        
        foreach ($this->schema as $tableName => $tableInfo) {
            // Skip if tableInfo is not an array or doesn't have the expected structure
            if (!is_array($tableInfo) || !isset($tableInfo['columns'])) {
                continue;
            }
            
            if (Str::contains($prompt, $tableName) || $this->hasMatchingColumns($prompt, $tableInfo['columns'])) {
                $relevantTables[$tableName] = $tableInfo;
            }
        }
        
        return !empty($relevantTables) ? $relevantTables : $this->schema;
    }
    
    protected function hasMatchingColumns(string $prompt, $columns): bool
    {
        if (!is_array($columns)) {
            return false;
        }

        foreach ($columns as $column => $info) {
            if (is_string($column) && Str::contains($prompt, $column)) {
                return true;
            }
        }
        return false;
    }
    
    protected function buildContextFromSchema(array $tables): string
    {
        $context = [];
        
        foreach ($tables as $tableName => $tableInfo) {
            if (!is_array($tableInfo) || !isset($tableInfo['columns']) || !is_array($tableInfo['columns'])) {
                continue;
            }
            
            $columns = [];
            
            foreach ($tableInfo['columns'] as $column => $info) {
                if (!is_array($info)) {
                    $info = ['type' => 'unknown'];
                }
                
                $columnInfo = "- {$column}: " . ($info['type'] ?? 'unknown');
                
                if (isset($tableInfo['foreign_keys'][$column])) {
                    $fk = $tableInfo['foreign_keys'][$column];
                    if (is_array($fk) && isset($fk['foreign_table'], $fk['foreign_column'])) {
                        $columnInfo .= " (references {$fk['foreign_table']}.{$fk['foreign_column']})";
                    }
                }
                $columns[] = $columnInfo;
            }
            
            if (!empty($columns)) {
                $context[] = "Table: {$tableName}\n" . implode("\n", $columns);
            }
        }
        
        return !empty($context) 
            ? "Database Schema Context:\n" . implode("\n\n", $context)
            : "No valid database schema information available.";
    }
    
    protected function suggestQueries(string $prompt, array $tables): array
    {
        $suggestions = [];
        $prompt = strtolower($prompt);
        
        try {
            // Always include a SELECT query suggestion
            $selectQuery = $this->buildSelectQuery($prompt, $tables);
            if (!str_starts_with(trim($selectQuery), '--')) {  // Skip if it's an error message
                $suggestions[] = $selectQuery;
            }
            
            // Check for specific query types
            if (empty($suggestions)) {
                if (Str::contains($prompt, ['insert', 'add', 'create', 'new'])) {
                    $insertQuery = $this->buildInsertQuery($prompt, $tables);
                    if (!str_starts_with(trim($insertQuery), '--')) {
                        $suggestions[] = $insertQuery;
                    }
                }
                
                if (Str::contains($prompt, ['update', 'change', 'modify', 'edit'])) {
                    $updateQuery = $this->buildUpdateQuery($prompt, $tables);
                    if (!str_starts_with(trim($updateQuery), '--')) {
                        $suggestions[] = $updateQuery;
                    }
                }
                
                if (Str::contains($prompt, ['delete', 'remove', 'erase'])) {
                    $deleteQuery = $this->buildDeleteQuery($prompt, $tables);
                    if (!str_starts_with(trim($deleteQuery), '--')) {
                        $suggestions[] = $deleteQuery;
                    }
                }
            }
            
            // If no specific queries matched, return at least the SELECT query (even if it's an error)
            if (empty($suggestions)) {
                $suggestions[] = $selectQuery;
            }
            
        } catch (\Exception $e) {
            // Log the error and return a helpful message
            \Log::error('Error generating query suggestions: ' . $e->getMessage());
            $suggestions[] = "-- Error generating query suggestions: " . $e->getMessage();
        }
        
        return $suggestions;
    }
    
    protected function buildSelectQuery(string $prompt, array $tables): string
    {
        $table = array_key_first($tables);
        if (!isset($tables[$table]['columns']) || !is_array($tables[$table]['columns'])) {
            return "-- Could not determine columns for table '{$table}'";
        }
        
        $columns = array_filter(array_keys($tables[$table]['columns']), function($column) {
            return is_string($column) && !in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at']);
        });
        
        if (empty($columns)) {
            return "-- No valid columns found for table '{$table}'";
        }
        
        $columnsStr = implode(', ', $columns);
        return "SELECT {$columnsStr} FROM {$table} WHERE [condition] LIMIT 10;";
    }
    
    protected function buildInsertQuery(string $prompt, array $tables): string
    {
        $table = array_key_first($tables);
        if (!isset($tables[$table]['columns']) || !is_array($tables[$table]['columns'])) {
            return "-- Could not determine columns for table '{$table}'";
        }
        
        $columns = array_filter(array_keys($tables[$table]['columns']), function($column) {
            return is_string($column) && !in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at']);
        });
        
        if (empty($columns)) {
            return "-- No valid columns found for insert into table '{$table}'";
        }
        
        $columnsStr = implode(', ', $columns);
        $values = implode(', ', array_fill(0, count($columns), '?'));
        
        return "-- Insert a new record\nINSERT INTO {$table} ({$columnsStr}) VALUES ({$values});";
    }
    
    protected function buildUpdateQuery(string $prompt, array $tables): string
    {
        $table = array_key_first($tables);
        if (!isset($tables[$table]['columns']) || !is_array($tables[$table]['columns'])) {
            return "-- Could not determine columns for table '{$table}'";
        }
        
        $updates = [];
        $excludedColumns = ['id', 'created_at', 'updated_at', 'deleted_at'];
        
        foreach ($tables[$table]['columns'] as $column => $info) {
            if (is_string($column) && !in_array($column, $excludedColumns)) {
                $updates[] = "{$column} = ?";
            }
        }
        
        if (empty($updates)) {
            return "-- No valid columns found for update in table '{$table}'";
        }
        
        $updatesStr = implode(",\n    ", $updates);
        return "-- Update existing records\nUPDATE {$table} \nSET {$updatesStr} \nWHERE [condition];";
    }
    
    protected function buildDeleteQuery(string $prompt, array $tables): string
    {
        $table = array_key_first($tables);
        return "-- Delete records (be careful!)\nDELETE FROM {$table} \nWHERE [condition];";
    }
    
    public function executeSafeQuery(string $query, array $bindings = [])
    {
        // Only allow SELECT queries for safety
        if (!Str::startsWith(trim(strtoupper($query)), 'SELECT')) {
            throw new \InvalidArgumentException('Only SELECT queries are allowed for safety reasons');
        }
        
        return DB::select($query, $bindings);
    }
}
