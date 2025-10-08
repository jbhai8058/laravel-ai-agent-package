<?php

namespace LaravelAI\SmartAgent\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class DatabaseSchemaService
{
    protected array $schema = [];

    public function __construct()
    {
        $this->loadSchema();
    }

    public function loadSchema(): void
    {
        // Get all tables from the database
        $tables = $this->getTables();
        
        // Process each table and add to schema
        foreach ($tables as $table) {
            try {
                $this->schema[$table] = $this->getTableSchema($table);
            } catch (\Exception $e) {
                // Log error but continue with other tables
                \Log::error("Failed to load schema for table {$table}: " . $e->getMessage());
                continue;
            }
        }
        
        // Get all stored procedures if needed
        $this->loadStoredProcedures();
    }
    
    protected function loadStoredProcedures(): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        
        if ($driver === 'mysql') {
            $this->loadMysqlProcedures();
        }
        // Add other database drivers as needed
    }
    
    protected function loadMysqlProcedures(): void
    {
        try {
            $database = config("database.connections.mysql.database");
            $procedures = DB::select(
                "SHOW PROCEDURE STATUS WHERE Db = ?", 
                [$database]
            );
            
            foreach ($procedures as $procedure) {
                $this->schema['procedures'][$procedure->Name] = [
                    'type' => 'procedure',
                    'name' => $procedure->Name,
                    'created' => $procedure->Created,
                    'modified' => $procedure->Modified,
                    'definer' => $procedure->Definer,
                ];
            }
        } catch (\Exception $e) {
            \Log::error("Failed to load MySQL procedures: " . $e->getMessage());
        }
    }

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function getTableSchema(string $table): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);
        $schema = [];

        foreach ($columns as $column) {
            $schema[$column] = $this->getColumnType($table, $column);
        }

        return [
            'columns' => $schema,
            'indexes' => $this->getIndexes($table),
            'foreign_keys' => $this->getForeignKeys($table),
        ];
    }

    protected function getTables(): array
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        return match ($driver) {
            'mysql' => $this->getMysqlTables(),
            'pgsql' => $this->getPostgresTables(),
            'sqlsrv' => $this->getSqlServerTables(),
            'sqlite' => $this->getSqliteTables(),
            default => [],
        };
    }

    protected function getMysqlTables(): array
    {
        return array_map(
            fn($table) => $table->{'Tables_in_' . config('database.connections.mysql.database')},
            DB::select('SHOW TABLES')
        );
    }

    protected function getPostgresTables(): array
    {
        return array_column(
            DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'"),
            'table_name'
        );
    }

    protected function getSqlServerTables(): array
    {
        return array_column(
            DB::select("SELECT table_name FROM information_schema.tables WHERE table_type = 'BASE TABLE'"),
            'table_name'
        );
    }

    protected function getSqliteTables(): array
    {
        return array_column(
            DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"),
            'name'
        );
    }

    protected function getColumnType(string $table, string $column): array
    {
        $connection = DB::connection();
        $doctrineColumn = $connection->getDoctrineColumn($table, $column);
        
        try {
            $type = $doctrineColumn->getType()->getName();
            
            // Handle ENUM type specifically for MySQL
            if ($type === 'string' && $connection->getDriverName() === 'mysql') {
                $type = $this->getMysqlColumnType($connection, $table, $column);
            }
            
            return [
                'type' => $type,
                'nullable' => !$doctrineColumn->getNotnull(),
                'default' => $doctrineColumn->getDefault(),
                'comment' => $doctrineColumn->getComment(),
            ];
        } catch (\Exception $e) {
            // Fallback for unsupported types
            return [
                'type' => 'unknown',
                'nullable' => true,
                'default' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    protected function getMysqlColumnType($connection, string $table, string $column): string
    {
        // Get the actual column type from information_schema
        $type = $connection->selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS 
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [
                $connection->getDatabaseName(),
                $table,
                $column
            ]
        );
        
        return $type ? $type->COLUMN_TYPE : 'string';
    }

    protected function getIndexes(string $table): array
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'mysql') {
            return $this->getMysqlIndexes($table);
        }

        // Add other database drivers as needed
        return [];
    }

    protected function getMysqlIndexes(string $table): array
    {
        $indexes = [];
        $results = DB::select("SHOW INDEXES FROM `{$table}`");

        foreach ($results as $result) {
            $indexes[$result->Key_name]['columns'][] = $result->Column_name;
            $indexes[$result->Key_name]['unique'] = !$result->Non_unique;
        }

        return $indexes;
    }

    protected function getForeignKeys(string $table): array
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'mysql') {
            return $this->getMysqlForeignKeys($table);
        }

        // Add other database drivers as needed
        return [];
    }

    protected function getMysqlForeignKeys(string $table): array
    {
        $foreignKeys = [];
        $database = config('database.connections.mysql.database');
        
        $results = DB::select("
            SELECT 
                COLUMN_NAME, 
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM 
                INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE 
                TABLE_SCHEMA = '{$database}' 
                AND TABLE_NAME = '{$table}'
                AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        foreach ($results as $result) {
            $foreignKeys[$result->COLUMN_NAME] = [
                'foreign_table' => $result->REFERENCED_TABLE_NAME,
                'foreign_column' => $result->REFERENCED_COLUMN_NAME,
            ];
        }

        return $foreignKeys;
    }
}
