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
        $relevantTables = $this->getRelevantTables($prompt, $tables);
        $context = $this->buildContextFromSchema($relevantTables);
        
        // Check if we need to handle data insertion
        $shouldInsertData = $this->shouldInsertData($prompt, $relevantTables);
        $executedQuery = null;
        
        if ($shouldInsertData) {
            $executedQuery = $this->handleDataInsertion($prompt, $relevantTables);
        }
        
        return [
            'context' => $context,
            'suggested_queries' => $this->suggestQueries($prompt, $relevantTables),
            'executed_query' => $executedQuery
        ];
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
        
        // Simple query type detection
        if (Str::contains($prompt, ['select', 'get', 'find', 'show'])) {
            $suggestions[] = $this->buildSelectQuery($prompt, $tables);
        }
        
        if (Str::contains($prompt, ['insert', 'add', 'create'])) {
            $suggestions[] = $this->buildInsertQuery($prompt, $tables);
        }
        
        if (Str::contains($prompt, ['update', 'change', 'modify'])) {
            $suggestions[] = $this->buildUpdateQuery($prompt, $tables);
        }
        
        if (Str::contains($prompt, ['delete', 'remove'])) {
            $suggestions[] = $this->buildDeleteQuery($prompt, $tables);
        }
        
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
