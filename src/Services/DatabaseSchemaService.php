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
        $tables = $this->getTables();
        
        foreach ($tables as $table) {
            $this->schema[$table] = $this->getTableSchema($table);
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
        $type = $connection->getDoctrineColumn($table, $column)->getType()->getName();
        
        return [
            'type' => $type,
            'nullable' => !$connection->getDoctrineColumn($table, $column)->getNotnull(),
            'default' => $connection->getDoctrineColumn($table, $column)->getDefault(),
        ];
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
