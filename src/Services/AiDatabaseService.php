<?php

namespace MuhammadJunaidRehmanSiddiqui\AiAgents\Services;

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
        
        return [
            'context' => $context,
            'suggested_queries' => $this->suggestQueries($prompt, $relevantTables)
        ];
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
            if (Str::contains($prompt, $tableName) || $this->hasMatchingColumns($prompt, $tableInfo['columns'])) {
                $relevantTables[$tableName] = $tableInfo;
            }
        }
        
        return !empty($relevantTables) ? $relevantTables : $this->schema;
    }
    
    protected function hasMatchingColumns(string $prompt, array $columns): bool
    {
        foreach ($columns as $column => $info) {
            if (Str::contains($prompt, $column)) {
                return true;
            }
        }
        return false;
    }
    
    protected function buildContextFromSchema(array $tables): string
    {
        $context = [];
        
        foreach ($tables as $tableName => $tableInfo) {
            $columns = [];
            
            foreach ($tableInfo['columns'] as $column => $info) {
                $columnInfo = "- {$column}: {$info['type']}";
                if (isset($tableInfo['foreign_keys'][$column])) {
                    $fk = $tableInfo['foreign_keys'][$column];
                    $columnInfo .= " (references {$fk['foreign_table']}.{$fk['foreign_column']})";
                }
                $columns[] = $columnInfo;
            }
            
            $context[] = "Table: {$tableName}\n" . implode("\n", $columns);
        }
        
        return "Database Schema Context:\n" . implode("\n\n", $context);
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
        
        return array_filter($suggestions);
    }
    
    protected function buildSelectQuery(string $prompt, array $tables): string
    {
        $table = array_key_first($tables);
        $columns = implode(', ', array_keys($tables[$table]['columns']));
        
        return "SELECT {$columns} FROM {$table} WHERE [condition] LIMIT 10;";
    }
    
    protected function buildInsertQuery(string $prompt, array $tables): string
    {
        $table = array_key_first($tables);
        $columns = [];
        
        foreach ($tables[$table]['columns'] as $column => $info) {
            if (!in_array($column, ['id', 'created_at', 'updated_at'])) {
                $columns[] = $column;
            }
        }
        
        $columnsStr = implode(', ', $columns);
        $values = implode(', ', array_fill(0, count($columns), '?'));
        
        return "INSERT INTO {$table} ({$columnsStr}) VALUES ({$values});";
    }
    
    protected function buildUpdateQuery(string $prompt, array $tables): string
    {
        $table = array_key_first($tables);
        $updates = [];
        
        foreach ($tables[$table]['columns'] as $column => $info) {
            if (!in_array($column, ['id', 'created_at', 'updated_at'])) {
                $updates[] = "{$column} = ?";
            }
        }
        
        $updatesStr = implode(', ', $updates);
        return "UPDATE {$table} SET {$updatesStr} WHERE [condition];";
    }
    
    protected function buildDeleteQuery(string $prompt, array $tables): string
    {
        $table = array_key_first($tables);
        return "DELETE FROM {$table} WHERE [condition];";
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
