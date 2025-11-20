<?php

namespace Bites\VectorIndexer\Services\Vector;

use Bites\VectorIndexer\Models\VectorConfiguration;
use Bites\VectorIndexer\Models\VectorRelationshipWatcher;
use Bites\VectorIndexer\Observers\DynamicVectorObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class VectorObserverManager
{
    protected static array $registeredObservers = [];
    
    /**
     * Register observer for a model and all its watched relationships
     */
    public function registerObservers(string $modelClass): void
    {
        $config = VectorConfiguration::where('model_class', $modelClass)
            ->where('enabled', true)
            ->first();
            
        if (!$config) {
            Log::warning("No enabled configuration found for {$modelClass}");
            return;
        }
        
        // Register observer for main model
        $this->registerModelObserver($modelClass, $config);
        
        // Register observers for all watched relationships
        $watchers = VectorRelationshipWatcher::where('vector_configuration_id', $config->id)
            ->where('enabled', true)
            ->get();
            
        foreach ($watchers as $watcher) {
            $this->registerRelationshipObserver($watcher);
        }
        
        Log::info("Registered vector observers for {$modelClass}", [
            'watchers_count' => $watchers->count(),
        ]);
    }
    
    /**
     * Register observer for main model
     */
    protected function registerModelObserver(string $modelClass, VectorConfiguration $config): void
    {
        if (isset(self::$registeredObservers[$modelClass])) {
            Log::debug("Observer already registered for {$modelClass}");
            return;
        }
        
        $observer = new DynamicVectorObserver($config);
        $modelClass::observe($observer);
        
        self::$registeredObservers[$modelClass] = true;
        
        Log::info("Registered vector observer for {$modelClass}");
    }
    
    /**
     * Register observer for relationship model
     */
    protected function registerRelationshipObserver(VectorRelationshipWatcher $watcher): void
    {
        $relatedModel = $watcher->related_model;
        
        if (isset(self::$registeredObservers[$relatedModel])) {
            Log::debug("Relationship observer already registered for {$relatedModel}");
            return;
        }
        
        // Create anonymous observer class for this relationship
        $observer = new class($watcher) {
            protected $watcher;
            
            public function __construct($watcher)
            {
                $this->watcher = $watcher;
            }
            
            public function created($model)
            {
                $this->handleChange($model, 'created');
            }
            
            public function updated($model)
            {
                // Only trigger if watched fields changed
                if ($this->hasWatchedFieldsChanged($model)) {
                    $this->handleChange($model, 'updated');
                }
            }
            
            public function deleted($model)
            {
                $this->handleChange($model, 'deleted');
            }
            
            protected function handleChange($model, $event)
            {
                // Find parent model
                $parent = $this->findParent($model);
                
                if (!$parent) {
                    \Log::debug("Could not find parent for relationship change", [
                        'watcher_id' => $this->watcher->id,
                        'model_class' => get_class($model),
                        'model_id' => $model->id,
                    ]);
                    return;
                }
                
                \Log::info("Relationship change detected", [
                    'relationship' => $this->watcher->relationship_name,
                    'event' => $event,
                    'related_model' => get_class($model),
                    'parent_model' => get_class($parent),
                    'parent_id' => $parent->id,
                ]);
                
                // Queue reindex of parent
                dispatch(new \App\Jobs\Vector\ReindexRelatedJob(
                    $this->watcher->parent_model,
                    $parent->id,
                    $this->watcher->relationship_name,
                    $event
                ));
            }
            
            protected function hasWatchedFieldsChanged($model): bool
            {
                $watchFields = $this->watcher->watch_fields;
                
                // If no specific fields to watch, watch all changes
                if (empty($watchFields)) {
                    return $model->isDirty();
                }
                
                // Check if any watched field changed
                foreach ($watchFields as $field) {
                    if ($model->wasChanged($field)) {
                        return true;
                    }
                }
                
                return false;
            }
            
            protected function findParent($model)
            {
                // Try to find parent through inverse relationship
                $inverseRelation = $this->getInverseRelationship();
                
                if ($inverseRelation && method_exists($model, $inverseRelation)) {
                    try {
                        return $model->$inverseRelation;
                    } catch (\Throwable $e) {
                        \Log::debug("Could not load inverse relationship", [
                            'relationship' => $inverseRelation,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                
                // Try common inverse relationship names
                $commonInverses = $this->guessInverseRelationships();
                foreach ($commonInverses as $inverse) {
                    if (method_exists($model, $inverse)) {
                        try {
                            return $model->$inverse;
                        } catch (\Throwable $e) {
                            continue;
                        }
                    }
                }
                
                return null;
            }
            
            protected function getInverseRelationship(): ?string
            {
                $name = $this->watcher->relationship_name;
                
                // Remove trailing 's' for simple plurals
                if (str_ends_with($name, 's')) {
                    return rtrim($name, 's');
                }
                
                return null;
            }
            
            protected function guessInverseRelationships(): array
            {
                $parentClass = $this->watcher->parent_model;
                $parentBaseName = class_basename($parentClass);
                
                // Common patterns
                return [
                    strtolower($parentBaseName), // EmailCache -> emailCache
                    lcfirst($parentBaseName), // EmailCache -> emailCache
                    str_replace('_', '', strtolower($parentBaseName)), // email_cache -> emailcache
                ];
            }
        };
        
        $relatedModel::observe($observer);
        self::$registeredObservers[$relatedModel] = true;
        
        Log::info("Registered relationship observer", [
            'related_model' => $relatedModel,
            'parent_model' => $watcher->parent_model,
            'relationship' => $watcher->relationship_name,
        ]);
    }
    
    /**
     * Unregister all observers for a model
     */
    public function unregisterObservers(string $modelClass): void
    {
        // Laravel doesn't provide a direct way to unregister observers
        // We mark the config as disabled instead
        VectorConfiguration::where('model_class', $modelClass)
            ->update(['enabled' => false]);
            
        VectorRelationshipWatcher::whereHas('configuration', function($q) use ($modelClass) {
            $q->where('model_class', $modelClass);
        })->update(['enabled' => false]);
        
        unset(self::$registeredObservers[$modelClass]);
        
        Log::info("Unregistered vector observers for {$modelClass}");
    }
    
    /**
     * Register all enabled configurations
     */
    public function registerAll(): void
    {
        $configurations = VectorConfiguration::enabled()->get();
        
        foreach ($configurations as $config) {
            try {
                $this->registerObservers($config->model_class);
            } catch (\Throwable $e) {
                Log::error("Failed to register observers for {$config->model_class}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::info("Registered all vector observers", [
            'count' => $configurations->count(),
        ]);
    }
    
    /**
     * Check if observers are registered for a model
     */
    public function isRegistered(string $modelClass): bool
    {
        return isset(self::$registeredObservers[$modelClass]);
    }
    
    /**
     * Get all registered observers
     */
    public function getRegistered(): array
    {
        return array_keys(self::$registeredObservers);
    }
    
    /**
     * Clear all registered observers
     */
    public function clearAll(): void
    {
        self::$registeredObservers = [];
        Log::info("Cleared all registered vector observers");
    }
}
