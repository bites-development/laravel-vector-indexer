<?php

namespace Bites\VectorIndexer\Services\Vector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;

class RelationshipAnalyzer
{
    protected SchemaAnalyzer $schemaAnalyzer;
    protected array $visited = [];

    public function __construct()
    {
        $this->schemaAnalyzer = new SchemaAnalyzer();
    }

    /**
     * Analyze all relationships of a model recursively
     * Returns relationships and eager load map for N+1 prevention
     */
    public function analyze(string $modelClass, int $maxDepth = 3): array
    {
        $this->visited = []; // Reset visited tracker
        $relationships = [];
        $eagerLoadMap = [];
        
        $this->analyzeRecursive(
            $modelClass,
            $relationships,
            $eagerLoadMap,
            0,
            $maxDepth
        );
        
        return [
            'relationships' => $relationships,
            'eager_load_map' => array_values(array_unique($eagerLoadMap)),
        ];
    }

    /**
     * Recursively analyze relationships
     */
    protected function analyzeRecursive(
        string $modelClass,
        array &$relationships,
        array &$eagerLoadMap,
        int $currentDepth,
        int $maxDepth,
        string $prefix = ''
    ): void {
        // Stop if max depth reached
        if ($currentDepth >= $maxDepth) {
            return;
        }

        // Prevent circular references
        $visitKey = $modelClass . ':' . $currentDepth;
        if (isset($this->visited[$visitKey])) {
            return;
        }
        $this->visited[$visitKey] = true;

        try {
            $model = new $modelClass;
            $reflection = new ReflectionClass($model);
            
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Skip if method has parameters
                if ($method->getNumberOfParameters() > 0) {
                    continue;
                }

                // Skip magic methods and common non-relationship methods
                if ($this->shouldSkipMethod($method->getName())) {
                    continue;
                }

                // Try to detect if this is a relationship method
                if ($this->isRelationshipMethod($method, $model)) {
                    $relationName = $method->getName();
                    
                    try {
                        $relation = $model->$relationName();
                        $relatedClass = get_class($relation->getRelated());
                        $relationType = class_basename(get_class($relation));
                        
                        $fullPath = $prefix ? "{$prefix}.{$relationName}" : $relationName;
                        
                        // Add to eager load map for N+1 prevention
                        $eagerLoadMap[] = $fullPath;
                        
                        // Get text fields from related model
                        $textFields = $this->getTextFieldsForModel($relatedClass);
                        
                        // Store relationship info
                        $relationships[$fullPath] = [
                            'name' => $relationName,
                            'type' => $relationType,
                            'model' => $relatedClass,
                            'depth' => $currentDepth + 1,
                            'parent' => $modelClass,
                            'path' => $fullPath,
                            'fields' => $textFields,
                            'enabled' => true, // Can be disabled in config
                        ];
                        
                        // Recurse into nested relationships
                        // Only if it's not a circular reference
                        if ($relatedClass !== $modelClass) {
                            $this->analyzeRecursive(
                                $relatedClass,
                                $relationships,
                                $eagerLoadMap,
                                $currentDepth + 1,
                                $maxDepth,
                                $fullPath
                            );
                        }
                    } catch (\Throwable $e) {
                        // Skip relationships that can't be instantiated
                        continue;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Skip models that can't be analyzed
            return;
        }
    }

    /**
     * Check if a method is a relationship method
     */
    protected function isRelationshipMethod(ReflectionMethod $method, Model $model): bool
    {
        // Skip if method has parameters
        if ($method->getNumberOfParameters() > 0) {
            return false;
        }

        // Skip if not public
        if (!$method->isPublic()) {
            return false;
        }

        // Skip if static
        if ($method->isStatic()) {
            return false;
        }

        // Skip if from base Model class or traits
        if ($method->getDeclaringClass()->getName() === Model::class) {
            return false;
        }

        try {
            $return = $method->invoke($model);
            return $return instanceof Relation;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Methods to skip (not relationships)
     */
    protected function shouldSkipMethod(string $methodName): bool
    {
        $skipMethods = [
            '__construct', '__destruct', '__call', '__callStatic',
            '__get', '__set', '__isset', '__unset', '__toString',
            '__invoke', '__clone', '__sleep', '__wakeup',
            'getTable', 'getKey', 'getKeyName', 'getKeyType',
            'getIncrementing', 'getConnectionName', 'getConnection',
            'getFillable', 'getGuarded', 'getCasts', 'getDates',
            'getHidden', 'getVisible', 'getAppends', 'getMutatedAttributes',
            'getAttribute', 'setAttribute', 'getAttributes', 'setAttributes',
            'getOriginal', 'getDirty', 'getChanges', 'wasChanged',
            'getRouteKey', 'getRouteKeyName', 'resolveRouteBinding',
            'toArray', 'toJson', 'jsonSerialize', 'fresh', 'refresh',
            'replicate', 'is', 'isNot', 'getQueueableId', 'getQueueableRelations',
            'getQueueableConnection', 'newCollection', 'newQuery', 'newQueryWithoutScopes',
            'newEloquentBuilder', 'newBaseQueryBuilder', 'newPivot',
            'scopeQuery', 'boot', 'booted', 'bootIfNotBooted',
        ];

        return in_array($methodName, $skipMethods) || 
               str_starts_with($methodName, 'scope') ||
               str_starts_with($methodName, 'get') ||
               str_starts_with($methodName, 'set') ||
               str_starts_with($methodName, 'is') ||
               str_starts_with($methodName, 'has');
    }

    /**
     * Get text fields for a model
     */
    protected function getTextFieldsForModel(string $modelClass): array
    {
        try {
            $model = new $modelClass;
            $table = $model->getTable();
            
            return $this->schemaAnalyzer->getTextColumns($table);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Build eager loading paths from relationships
     */
    public function buildEagerLoadPaths(array $relationships): array
    {
        $paths = [];
        
        foreach ($relationships as $path => $relationInfo) {
            if ($relationInfo['enabled'] ?? true) {
                $paths[] = $path;
            }
        }
        
        return array_unique($paths);
    }

    /**
     * Get relationships at a specific depth
     */
    public function getRelationshipsAtDepth(array $relationships, int $depth): array
    {
        return array_filter($relationships, fn($rel) => $rel['depth'] === $depth);
    }

    /**
     * Get shallow relationships (depth 1)
     */
    public function getShallowRelationships(array $relationships): array
    {
        return $this->getRelationshipsAtDepth($relationships, 1);
    }

    /**
     * Get deep relationships (depth > 1)
     */
    public function getDeepRelationships(array $relationships): array
    {
        return array_filter($relationships, fn($rel) => $rel['depth'] > 1);
    }

    /**
     * Find the inverse relationship name
     * Simple heuristic for common cases
     */
    public function findInverseRelationship(string $relationshipName, string $relationType): ?string
    {
        // For hasMany/hasOne, the inverse is usually singular
        if (in_array($relationType, ['HasMany', 'HasOne'])) {
            // Remove trailing 's' for simple plurals
            if (str_ends_with($relationshipName, 's')) {
                return rtrim($relationshipName, 's');
            }
        }
        
        // For belongsTo, the inverse might be plural
        if ($relationType === 'BelongsTo') {
            // Simple pluralization
            if (!str_ends_with($relationshipName, 's')) {
                return $relationshipName . 's';
            }
        }
        
        return null;
    }

    /**
     * Check if a relationship path would cause circular reference
     */
    public function wouldCauseCircularReference(string $modelClass, string $relatedClass, array $visited): bool
    {
        return $modelClass === $relatedClass || in_array($relatedClass, $visited);
    }

    /**
     * Get relationship statistics
     */
    public function getStatistics(array $relationships): array
    {
        $stats = [
            'total' => count($relationships),
            'by_depth' => [],
            'by_type' => [],
            'with_text_fields' => 0,
            'max_depth' => 0,
        ];

        foreach ($relationships as $rel) {
            // Count by depth
            $depth = $rel['depth'];
            $stats['by_depth'][$depth] = ($stats['by_depth'][$depth] ?? 0) + 1;
            $stats['max_depth'] = max($stats['max_depth'], $depth);
            
            // Count by type
            $type = $rel['type'];
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
            
            // Count relationships with text fields
            if (!empty($rel['fields'])) {
                $stats['with_text_fields']++;
            }
        }

        return $stats;
    }

    /**
     * Format relationship tree for display
     */
    public function formatTree(array $relationships): string
    {
        $tree = "Relationship Tree:\n";
        $tree .= str_repeat('=', 60) . "\n\n";

        $byDepth = [];
        foreach ($relationships as $path => $rel) {
            $byDepth[$rel['depth']][] = [
                'path' => $path,
                'type' => $rel['type'],
                'model' => class_basename($rel['model']),
                'fields' => count($rel['fields']),
            ];
        }

        ksort($byDepth);

        foreach ($byDepth as $depth => $rels) {
            $indent = str_repeat('  ', $depth);
            $tree .= "Depth {$depth}:\n";
            
            foreach ($rels as $rel) {
                $tree .= "{$indent}• {$rel['path']} ({$rel['type']}) → {$rel['model']}";
                if ($rel['fields'] > 0) {
                    $tree .= " [{$rel['fields']} text fields]";
                }
                $tree .= "\n";
            }
            $tree .= "\n";
        }

        return $tree;
    }
}
