<?php

namespace Bites\VectorIndexer\Services\Vector;

use Bites\VectorIndexer\Models\VectorConfiguration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class DataLoaderService
{
    /**
     * Load model with all relationships efficiently
     * Prevents N+1 queries using eager loading map
     */
    public function loadWithRelationships(Model $model, VectorConfiguration $config): Model
    {
        $eagerLoadPaths = $config->eager_load_map ?? [];
        
        if (empty($eagerLoadPaths)) {
            return $model;
        }
        
        // Load all relationships in one query - NO N+1!
        $model->load($eagerLoadPaths);
        
        return $model;
    }
    
    /**
     * Batch load multiple models with relationships
     * Extremely efficient - single query with eager loading
     */
    public function batchLoad(
        string $modelClass,
        array $ids,
        VectorConfiguration $config
    ): Collection {
        $eagerLoadPaths = $config->eager_load_map ?? [];
        
        // Single query with eager loading - NO N+1!
        return $modelClass::with($eagerLoadPaths)
            ->whereIn('id', $ids)
            ->get();
    }
    
    /**
     * Extract all text content from model and relationships
     */
    public function extractContent(Model $model, VectorConfiguration $config): array
    {
        $content = [];
        
        // Main model fields
        foreach ($config->fields as $field => $fieldConfig) {
            $value = $this->getFieldValue($model, $field);
            
            if ($value) {
                $content[] = [
                    'source' => 'main',
                    'field' => $field,
                    'value' => $value,
                    'weight' => $fieldConfig['weight'] ?? 1.0,
                    'chunk' => $fieldConfig['chunk'] ?? false,
                    'chunk_size' => $fieldConfig['chunk_size'] ?? 1000,
                    'chunk_overlap' => $fieldConfig['chunk_overlap'] ?? 200,
                ];
            }
        }
        
        // Relationship fields
        foreach ($config->relationships as $path => $relationConfig) {
            if (!($relationConfig['enabled'] ?? true)) {
                continue;
            }
            
            $relatedData = $this->extractFromRelationship($model, $path, $relationConfig);
            $content = array_merge($content, $relatedData);
        }
        
        return $content;
    }
    
    /**
     * Extract content from a relationship
     */
    protected function extractFromRelationship(Model $model, string $path, array $config): array
    {
        $content = [];
        $parts = explode('.', $path);
        $current = $model;
        
        // Navigate through relationship path
        foreach ($parts as $part) {
            if (!$current || !method_exists($current, $part)) {
                return [];
            }
            $current = $current->$part;
            
            // If null at any point, stop
            if ($current === null) {
                return [];
            }
        }
        
        // Handle collection or single model
        $items = $current instanceof Collection ? $current : [$current];
        
        foreach ($items as $item) {
            if (!$item) continue;
            
            foreach ($config['fields'] ?? [] as $field) {
                $value = $this->getFieldValue($item, $field);
                
                if ($value) {
                    $content[] = [
                        'source' => $path,
                        'field' => $field,
                        'value' => $value,
                        'weight' => $config['weight'] ?? 0.5,
                        'chunk' => false, // Don't chunk relationship content by default
                    ];
                }
            }
        }
        
        return $content;
    }
    
    /**
     * Get field value from model, handling accessors
     */
    protected function getFieldValue(Model $model, string $field): ?string
    {
        try {
            $value = $model->$field;
            
            // Convert to string if not already
            if ($value === null) {
                return null;
            }
            
            if (is_array($value) || is_object($value)) {
                return json_encode($value);
            }
            
            return (string) $value;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * Extract metadata fields from model
     */
    public function extractMetadata(Model $model, VectorConfiguration $config): array
    {
        $metadata = [];
        
        foreach ($config->metadata_fields ?? [] as $field) {
            try {
                $value = $model->$field;
                
                // Handle different value types
                if ($value instanceof \DateTimeInterface) {
                    $metadata[$field] = $value->toIso8601String();
                } elseif (is_bool($value)) {
                    $metadata[$field] = $value;
                } elseif (is_numeric($value)) {
                    $metadata[$field] = $value;
                } elseif (is_string($value)) {
                    $metadata[$field] = $value;
                } elseif (is_array($value)) {
                    $metadata[$field] = $value;
                } else {
                    $metadata[$field] = (string) $value;
                }
            } catch (\Throwable $e) {
                // Skip fields that can't be accessed
                continue;
            }
        }
        
        return $metadata;
    }
    
    /**
     * Combine text content into chunks
     */
    public function combineContent(array $contentItems): array
    {
        $combined = [];
        
        foreach ($contentItems as $item) {
            if ($item['chunk']) {
                // This will be chunked separately
                $combined[] = $item;
            } else {
                // Combine non-chunked items
                $key = $item['source'] . ':' . $item['field'];
                $combined[$key] = $item;
            }
        }
        
        return array_values($combined);
    }
    
    /**
     * Get total text size for a model
     */
    public function getTotalTextSize(Model $model, VectorConfiguration $config): int
    {
        $content = $this->extractContent($model, $config);
        $totalSize = 0;
        
        foreach ($content as $item) {
            $totalSize += strlen($item['value']);
        }
        
        return $totalSize;
    }
    
    /**
     * Check if model has sufficient content for indexing
     */
    public function hasSufficientContent(Model $model, VectorConfiguration $config, int $minSize = 10): bool
    {
        $content = $this->extractContent($model, $config);
        
        if (empty($content)) {
            return false;
        }
        
        $totalSize = 0;
        foreach ($content as $item) {
            $totalSize += strlen($item['value']);
        }
        
        return $totalSize >= $minSize;
    }
    
    /**
     * Get content summary for debugging
     */
    public function getContentSummary(array $content): array
    {
        $summary = [
            'total_items' => count($content),
            'by_source' => [],
            'total_characters' => 0,
            'items_to_chunk' => 0,
        ];
        
        foreach ($content as $item) {
            $source = $item['source'];
            $summary['by_source'][$source] = ($summary['by_source'][$source] ?? 0) + 1;
            $summary['total_characters'] += strlen($item['value']);
            
            if ($item['chunk']) {
                $summary['items_to_chunk']++;
            }
        }
        
        return $summary;
    }
}
