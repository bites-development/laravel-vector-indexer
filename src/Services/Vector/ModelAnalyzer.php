<?php

namespace Bites\VectorIndexer\Services\Vector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;

class ModelAnalyzer
{
    protected SchemaAnalyzer $schemaAnalyzer;
    protected RelationshipAnalyzer $relationshipAnalyzer;

    public function __construct()
    {
        $this->schemaAnalyzer = new SchemaAnalyzer();
        $this->relationshipAnalyzer = new RelationshipAnalyzer();
    }

    /**
     * Analyze a model and return comprehensive information
     */
    public function analyze(string $modelClass, int $maxDepth = 3): array
    {
        if (!class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class {$modelClass} does not exist");
        }

        if (!is_subclass_of($modelClass, Model::class)) {
            throw new \InvalidArgumentException("{$modelClass} is not an Eloquent model");
        }

        $model = new $modelClass;
        $table = $model->getTable();

        // Get all columns
        $columns = Schema::getColumnListing($table);

        // Analyze text fields
        $textFields = $this->analyzeTextFields($table, $columns);

        // Analyze relationships
        $relationshipAnalysis = $this->relationshipAnalyzer->analyze($modelClass, $maxDepth);

        // Identify metadata fields
        $metadataFields = $this->identifyMetadataFields($columns, $textFields);

        // Generate recommendations
        $recommendations = $this->generateRecommendations($textFields, $relationshipAnalysis);

        return [
            'model_class' => $modelClass,
            'table' => $table,
            'total_columns' => count($columns),
            'columns' => $columns,
            'text_fields' => $textFields,
            'metadata_fields' => $metadataFields,
            'relationships' => $relationshipAnalysis['relationships'],
            'eager_load_map' => $relationshipAnalysis['eager_load_map'],
            'recommendations' => $recommendations,
            'suggested_config' => $this->generateSuggestedConfig($modelClass, $table, $textFields, $relationshipAnalysis),
        ];
    }

    /**
     * Analyze text fields in the table
     */
    protected function analyzeTextFields(string $table, array $columns): array
    {
        $textFields = [];

        foreach ($columns as $column) {
            $columnType = $this->schemaAnalyzer->getColumnType($table, $column);

            if ($this->schemaAnalyzer->isTextColumn($columnType)) {
                $textFields[$column] = [
                    'type' => $columnType,
                    'weight' => $this->schemaAnalyzer->suggestWeight($column, $columnType),
                    'chunk' => $this->schemaAnalyzer->shouldChunk($column, $columnType),
                    'chunk_size' => $this->schemaAnalyzer->suggestChunkSize($columnType),
                ];
            }
        }

        return $textFields;
    }

    /**
     * Identify metadata fields (non-text fields to store but not embed)
     */
    protected function identifyMetadataFields(array $allColumns, array $textFields): array
    {
        $metadataFields = [];
        $textFieldNames = array_keys($textFields);

        // Common metadata fields
        $commonMetadata = ['id', 'created_at', 'updated_at', 'deleted_at'];

        // Fields that end with _id (foreign keys)
        $foreignKeys = array_filter($allColumns, fn($col) => str_ends_with($col, '_id'));

        // Boolean fields
        $booleanFields = array_filter($allColumns, fn($col) => 
            str_starts_with($col, 'is_') || 
            str_starts_with($col, 'has_') ||
            str_starts_with($col, 'can_')
        );

        // Status/type fields
        $statusFields = array_filter($allColumns, fn($col) => 
            str_ends_with($col, '_status') || 
            str_ends_with($col, '_type') ||
            $col === 'status' ||
            $col === 'type'
        );

        $metadataFields = array_unique(array_merge(
            $commonMetadata,
            $foreignKeys,
            $booleanFields,
            $statusFields
        ));

        // Remove text fields from metadata
        $metadataFields = array_diff($metadataFields, $textFieldNames);

        // Only include fields that actually exist in the table
        $metadataFields = array_intersect($metadataFields, $allColumns);

        return array_values($metadataFields);
    }

    /**
     * Generate recommendations based on analysis
     */
    protected function generateRecommendations(array $textFields, array $relationshipAnalysis): array
    {
        $recommendations = [];

        // Text field recommendations
        if (empty($textFields)) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'No text fields found. This model may not be suitable for vector indexing.',
            ];
        } elseif (count($textFields) === 1) {
            $recommendations[] = [
                'type' => 'info',
                'message' => 'Only one text field found. Consider if related models should be included.',
            ];
        } else {
            $recommendations[] = [
                'type' => 'success',
                'message' => count($textFields) . ' text fields found suitable for embedding.',
            ];
        }

        // Relationship recommendations
        $relationshipCount = count($relationshipAnalysis['relationships']);
        if ($relationshipCount > 0) {
            $recommendations[] = [
                'type' => 'success',
                'message' => "Found {$relationshipCount} relationships that can be watched for changes.",
            ];

            if ($relationshipCount > 10) {
                $recommendations[] = [
                    'type' => 'warning',
                    'message' => 'Many relationships detected. Consider limiting depth to improve performance.',
                ];
            }
        }

        // Chunking recommendations
        $chunkableFields = array_filter($textFields, fn($field) => $field['chunk']);
        if (!empty($chunkableFields)) {
            $recommendations[] = [
                'type' => 'info',
                'message' => count($chunkableFields) . ' fields will be chunked for better embedding quality.',
            ];
        }

        return $recommendations;
    }

    /**
     * Generate suggested configuration
     */
    protected function generateSuggestedConfig(
        string $modelClass,
        string $table,
        array $textFields,
        array $relationshipAnalysis
    ): array {
        $collectionName = str_replace('\\', '_', strtolower($modelClass)) . '_vectors';
        $collectionName = preg_replace('/[^a-z0-9_]/', '_', $collectionName);

        // Build fields configuration
        $fieldsConfig = [];
        foreach ($textFields as $fieldName => $fieldInfo) {
            $fieldsConfig[$fieldName] = [
                'weight' => $fieldInfo['weight'],
                'chunk' => $fieldInfo['chunk'],
            ];

            if ($fieldInfo['chunk']) {
                $fieldsConfig[$fieldName]['chunk_size'] = $fieldInfo['chunk_size'];
                $fieldsConfig[$fieldName]['chunk_overlap'] = (int)($fieldInfo['chunk_size'] * 0.2);
            }
        }

        // Build relationships configuration
        $relationshipsConfig = [];
        foreach ($relationshipAnalysis['relationships'] as $path => $relationInfo) {
            if ($relationInfo['depth'] <= 2) { // Only include shallow relationships by default
                $relationshipsConfig[$path] = [
                    'enabled' => true,
                    'fields' => $relationInfo['fields'],
                    'weight' => $relationInfo['depth'] === 1 ? 0.7 : 0.5,
                ];
            }
        }

        return [
            'model_class' => $modelClass,
            'collection_name' => $collectionName,
            'driver' => 'qdrant',
            'enabled' => true,
            'fields' => $fieldsConfig,
            'metadata_fields' => $this->identifyMetadataFields(
                Schema::getColumnListing($table),
                $textFields
            ),
            'filters' => $this->suggestFilters($table),
            'relationships' => $relationshipsConfig,
            'eager_load_map' => $relationshipAnalysis['eager_load_map'],
            'max_relationship_depth' => 3,
            'options' => [
                'embedding_model' => config('vector-indexer.openai.embedding_model', 'text-embedding-3-large'),
                'embedding_dimensions' => config('vector-indexer.openai.embedding_dimensions', 3072),
                'batch_size' => config('vector-indexer.queue.batch_size', 100),
            ],
        ];
    }

    /**
     * Suggest filter fields for Qdrant
     */
    protected function suggestFilters(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        $filters = [];

        foreach ($columns as $column) {
            $type = $this->schemaAnalyzer->getColumnType($table, $column);

            // Foreign keys
            if (str_ends_with($column, '_id')) {
                $filters[$column] = 'integer';
            }
            // Boolean fields
            elseif ($type === 'boolean' || str_starts_with($column, 'is_') || str_starts_with($column, 'has_')) {
                $filters[$column] = 'boolean';
            }
            // Status/type fields
            elseif (in_array($column, ['status', 'type']) || str_ends_with($column, '_status') || str_ends_with($column, '_type')) {
                $filters[$column] = 'keyword';
            }
            // Common filterable fields
            elseif (in_array($column, ['category', 'tag', 'label', 'priority'])) {
                $filters[$column] = 'keyword';
            }
        }

        return $filters;
    }

    /**
     * Quick check if a model is suitable for vector indexing
     */
    public function isSuitableForIndexing(string $modelClass): bool
    {
        try {
            $analysis = $this->analyze($modelClass, 1);
            return !empty($analysis['text_fields']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get a summary of the analysis
     */
    public function getSummary(array $analysis): string
    {
        $summary = "Model Analysis Summary for {$analysis['model_class']}\n";
        $summary .= str_repeat('=', 60) . "\n\n";
        $summary .= "Table: {$analysis['table']}\n";
        $summary .= "Total Columns: {$analysis['total_columns']}\n";
        $summary .= "Text Fields: " . count($analysis['text_fields']) . "\n";
        $summary .= "Metadata Fields: " . count($analysis['metadata_fields']) . "\n";
        $summary .= "Relationships: " . count($analysis['relationships']) . "\n";
        $summary .= "Eager Load Paths: " . count($analysis['eager_load_map']) . "\n\n";

        $summary .= "Recommendations:\n";
        foreach ($analysis['recommendations'] as $rec) {
            $icon = match($rec['type']) {
                'success' => '✓',
                'warning' => '⚠',
                'info' => 'ℹ',
                default => '•'
            };
            $summary .= "  {$icon} {$rec['message']}\n";
        }

        return $summary;
    }
}
