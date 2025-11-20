<?php

namespace Bites\VectorIndexer\Observers;

use Bites\VectorIndexer\Models\VectorConfiguration;
use Bites\VectorIndexer\Models\VectorIndexQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class DynamicVectorObserver
{
    protected VectorConfiguration $config;
    protected int $debounceSeconds;
    
    public function __construct(VectorConfiguration $config)
    {
        $this->config = $config;
        $this->debounceSeconds = config('vector-indexer.auto_indexing.debounce_seconds', 5);
    }
    
    /**
     * Handle the model "created" event
     */
    public function created(Model $model): void
    {
        if (!config('vector-indexer.auto_indexing.on_create', true)) {
            return;
        }
        
        Log::info("Vector observer: Model created", [
            'model' => get_class($model),
            'id' => $model->id,
        ]);
        
        $this->queueIndexing($model, 'index');
    }
    
    /**
     * Handle the model "updated" event
     */
    public function updated(Model $model): void
    {
        if (!config('vector-indexer.auto_indexing.on_update', true)) {
            return;
        }
        
        // Only reindex if watched fields changed
        if (!$this->shouldReindex($model)) {
            Log::debug("Vector observer: No watched fields changed", [
                'model' => get_class($model),
                'id' => $model->id,
            ]);
            return;
        }
        
        Log::info("Vector observer: Model updated", [
            'model' => get_class($model),
            'id' => $model->id,
            'changed_fields' => array_keys($model->getChanges()),
        ]);
        
        $this->queueIndexing($model, 'update');
    }
    
    /**
     * Handle the model "deleted" event
     */
    public function deleted(Model $model): void
    {
        if (!config('vector-indexer.auto_indexing.on_delete', true)) {
            return;
        }
        
        Log::info("Vector observer: Model deleted", [
            'model' => get_class($model),
            'id' => $model->id,
        ]);
        
        $this->queueIndexing($model, 'delete');
    }
    
    /**
     * Queue indexing operation
     */
    protected function queueIndexing(Model $model, string $action): void
    {
        try {
            // Check if there's a recent pending job (debouncing)
            if ($this->hasRecentPendingJob($model, $action)) {
                Log::debug("Vector observer: Debouncing - recent job exists", [
                    'model' => get_class($model),
                    'id' => $model->id,
                    'action' => $action,
                ]);
                return;
            }
            
            // Create or update queue item
            $queueItem = VectorIndexQueue::updateOrCreate(
                [
                    'vector_configuration_id' => $this->config->id,
                    'model_class' => get_class($model),
                    'model_id' => $model->id,
                    'action' => $action,
                    'status' => 'pending',
                ],
                [
                    'triggered_by' => 'observer',
                    'attempts' => 0,
                    'error_message' => null,
                ]
            );
            
            // Update configuration pending count
            $this->config->incrementPending();
            
            // Dispatch job
            if (config('vector-indexer.queue.enabled', true)) {
                dispatch(new \App\Jobs\Vector\IndexModelJob(
                    $this->config->id,
                    get_class($model),
                    $model->id,
                    $action
                ))->onQueue(config('vector-indexer.queue.queue_name', 'vector-indexing'));
            } else {
                // Process synchronously if queue disabled
                $job = new \App\Jobs\Vector\IndexModelJob(
                    $this->config->id,
                    get_class($model),
                    $model->id,
                    $action
                );
                $job->handle();
            }
            
            Log::info("Vector observer: Queued indexing", [
                'model' => get_class($model),
                'id' => $model->id,
                'action' => $action,
                'queue_item_id' => $queueItem->id,
            ]);
            
        } catch (\Throwable $e) {
            Log::error("Vector observer: Failed to queue indexing", [
                'model' => get_class($model),
                'id' => $model->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Check if should reindex based on changed fields
     */
    protected function shouldReindex(Model $model): bool
    {
        $watchedFields = array_keys($this->config->fields);
        
        // Check if any watched field changed
        foreach ($watchedFields as $field) {
            if ($model->wasChanged($field)) {
                return true;
            }
        }
        
        // Also check metadata fields if they're used for filtering
        $metadataFields = $this->config->metadata_fields ?? [];
        $filterFields = array_keys($this->config->filters ?? []);
        
        foreach (array_merge($metadataFields, $filterFields) as $field) {
            if ($model->wasChanged($field)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if there's a recent pending job (for debouncing)
     */
    protected function hasRecentPendingJob(Model $model, string $action): bool
    {
        if ($this->debounceSeconds <= 0) {
            return false;
        }
        
        $cutoff = now()->subSeconds($this->debounceSeconds);
        
        return VectorIndexQueue::where('vector_configuration_id', $this->config->id)
            ->where('model_class', get_class($model))
            ->where('model_id', $model->id)
            ->where('action', $action)
            ->where('status', 'pending')
            ->where('created_at', '>=', $cutoff)
            ->exists();
    }
    
    /**
     * Get the configuration
     */
    public function getConfiguration(): VectorConfiguration
    {
        return $this->config;
    }
}
