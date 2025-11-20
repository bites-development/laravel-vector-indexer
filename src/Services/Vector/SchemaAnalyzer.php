<?php

namespace Bites\VectorIndexer\Services\Vector;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class SchemaAnalyzer
{
    /**
     * Text column types that should be embedded
     */
    protected array $textColumnTypes = [
        'text',
        'mediumtext',
        'longtext',
        'string',
        'varchar',
        'char',
    ];

    /**
     * Check if a column type is suitable for text embedding
     */
    public function isTextColumn(string $columnType): bool
    {
        $columnType = strtolower($columnType);
        
        foreach ($this->textColumnTypes as $textType) {
            if (str_contains($columnType, $textType)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Suggest weight for a field based on its name and type
     */
    public function suggestWeight(string $columnName, string $columnType): float
    {
        $columnName = strtolower($columnName);
        
        // High priority fields
        if (in_array($columnName, ['title', 'name', 'subject', 'headline'])) {
            return 2.0;
        }
        
        // Medium-high priority
        if (in_array($columnName, ['summary', 'excerpt', 'abstract', 'intro'])) {
            return 1.5;
        }
        
        // Standard priority
        if (in_array($columnName, ['body', 'content', 'text', 'description', 'message'])) {
            return 1.0;
        }
        
        // Lower priority
        if (in_array($columnName, ['notes', 'comments', 'remarks'])) {
            return 0.7;
        }
        
        // Metadata text
        if (in_array($columnName, ['tags', 'keywords', 'labels', 'categories'])) {
            return 0.5;
        }
        
        // Default weight
        return 1.0;
    }

    /**
     * Determine if a field should be chunked
     */
    public function shouldChunk(string $columnName, string $columnType): bool
    {
        $columnType = strtolower($columnType);
        $columnName = strtolower($columnName);
        
        // Long text types should always be chunked
        if (str_contains($columnType, 'longtext') || str_contains($columnType, 'mediumtext')) {
            return true;
        }
        
        // Body/content fields should be chunked
        if (in_array($columnName, ['body', 'content', 'text', 'description', 'message'])) {
            return true;
        }
        
        // Text type with body-like names
        if (str_contains($columnType, 'text') && 
            (str_contains($columnName, 'body') || 
             str_contains($columnName, 'content') ||
             str_contains($columnName, 'text'))) {
            return true;
        }
        
        return false;
    }

    /**
     * Suggest chunk size based on column type
     */
    public function suggestChunkSize(string $columnType): int
    {
        $columnType = strtolower($columnType);
        
        if (str_contains($columnType, 'longtext')) {
            return 1500; // Larger chunks for very long text
        }
        
        if (str_contains($columnType, 'mediumtext')) {
            return 1000; // Medium chunks
        }
        
        if (str_contains($columnType, 'text')) {
            return 800; // Smaller chunks for regular text
        }
        
        return 500; // Default for varchar/string
    }

    /**
     * Get column type from database
     */
    public function getColumnType(string $table, string $column): string
    {
        try {
            return Schema::getColumnType($table, $column);
        } catch (\Throwable $e) {
            // Doctrine DBAL doesn't handle enum types well
            // Fall back to raw query
            if (str_contains($e->getMessage(), 'enum')) {
                try {
                    $result = DB::select("SHOW COLUMNS FROM {$table} WHERE Field = ?", [$column]);
                    if (!empty($result)) {
                        $type = $result[0]->Type ?? 'string';
                        // If it's an enum, treat it as string for our purposes
                        if (str_starts_with($type, 'enum')) {
                            return 'string';
                        }
                        return $type;
                    }
                } catch (\Throwable $e2) {
                    // If all else fails, return string
                    return 'string';
                }
            }
            
            // For other errors, return string as safe default
            return 'string';
        }
    }

    /**
     * Get all columns for a table
     */
    public function getColumns(string $table): array
    {
        return Schema::getColumnListing($table);
    }

    /**
     * Analyze all columns in a table
     */
    public function analyzeTable(string $table): array
    {
        $columns = $this->getColumns($table);
        $analysis = [];
        
        foreach ($columns as $column) {
            $type = $this->getColumnType($table, $column);
            
            $analysis[$column] = [
                'type' => $type,
                'is_text' => $this->isTextColumn($type),
                'suggested_weight' => $this->suggestWeight($column, $type),
                'should_chunk' => $this->shouldChunk($column, $type),
                'chunk_size' => $this->suggestChunkSize($type),
            ];
        }
        
        return $analysis;
    }

    /**
     * Get only text columns from a table
     */
    public function getTextColumns(string $table): array
    {
        $columns = $this->getColumns($table);
        $textColumns = [];
        
        foreach ($columns as $column) {
            $type = $this->getColumnType($table, $column);
            if ($this->isTextColumn($type)) {
                $textColumns[] = $column;
            }
        }
        
        return $textColumns;
    }

    /**
     * Estimate the size of text content in a column
     */
    public function estimateColumnSize(string $table, string $column): ?int
    {
        try {
            $type = $this->getColumnType($table, $column);
            
            return match(true) {
                str_contains($type, 'longtext') => 4294967295, // 4GB
                str_contains($type, 'mediumtext') => 16777215, // 16MB
                str_contains($type, 'text') => 65535, // 64KB
                str_contains($type, 'varchar') => 255, // Typical varchar
                default => null,
            };
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if a column likely contains structured data (JSON, XML, etc.)
     */
    public function isStructuredData(string $table, string $column): bool
    {
        $type = $this->getColumnType($table, $column);
        $columnName = strtolower($column);
        
        // JSON columns
        if (str_contains($type, 'json')) {
            return true;
        }
        
        // Common structured data column names
        if (in_array($columnName, ['data', 'metadata', 'payload', 'config', 'settings', 'options'])) {
            return true;
        }
        
        return false;
    }

    /**
     * Suggest if a column should be used as metadata (stored but not embedded)
     */
    public function shouldBeMetadata(string $table, string $column): bool
    {
        $type = $this->getColumnType($table, $column);
        $columnName = strtolower($column);
        
        // IDs and timestamps
        if (in_array($columnName, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
            return true;
        }
        
        // Foreign keys
        if (str_ends_with($columnName, '_id')) {
            return true;
        }
        
        // Boolean fields
        if (str_contains($type, 'boolean') || str_contains($type, 'tinyint(1)')) {
            return true;
        }
        
        // Numeric fields
        if (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float')) {
            return true;
        }
        
        // Dates
        if (str_contains($type, 'date') || str_contains($type, 'time')) {
            return true;
        }
        
        return false;
    }
}
