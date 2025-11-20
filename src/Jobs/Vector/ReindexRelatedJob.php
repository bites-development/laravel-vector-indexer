<?php

namespace Bites\VectorIndexer\Jobs\Vector;

use Bites\VectorIndexer\Models\VectorConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReindexRelatedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    protected string $parentModelClass;
    protected int $parentModelId;
    protected string $relationshipName;
    protected string $triggerEvent;

    /**
     * Create a new job instance
     */
    public function __construct(
        string $parentModelClass,
        int $parentModelId,
        string $relationshipName,
        string $triggerEvent
    ) {
        $this->parentModelClass = $parentModelClass;
        $this->parentModelId = $parentModelId;
        $this->relationshipName = $relationshipName;
        $this->triggerEvent = $triggerEvent;
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        try {
            // Find configuration for parent model
            $config = VectorConfiguration::where('model_class', $this->parentModelClass)
                ->where('enabled', true)
                ->first();
            
            if (!$config) {
                Log::debug("No configuration found for parent model", [
                    'model' => $this->parentModelClass,
                ]);
                return;
            }
            
            // Check if parent model still exists
            $parentExists = $this->parentModelClass::where('id', $this->parentModelId)->exists();
            
            if (!$parentExists) {
                Log::info("Parent model no longer exists, skipping reindex", [
                    'model' => $this->parentModelClass,
                    'id' => $this->parentModelId,
                ]);
                return;
            }
            
            Log::info("Reindexing parent due to relationship change", [
                'parent_model' => $this->parentModelClass,
                'parent_id' => $this->parentModelId,
                'relationship' => $this->relationshipName,
                'trigger_event' => $this->triggerEvent,
            ]);
            
            // Dispatch indexing job for parent
            dispatch(new IndexModelJob(
                $config->id,
                $this->parentModelClass,
                $this->parentModelId,
                'update'
            ))->onQueue(config('vector-indexer.queue.queue_name', 'vector-indexing'));
            
        } catch (\Throwable $e) {
            Log::error("Failed to reindex related model", [
                'parent_model' => $this->parentModelClass,
                'parent_id' => $this->parentModelId,
                'relationship' => $this->relationshipName,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Reindex related job failed", [
            'parent_model' => $this->parentModelClass,
            'parent_id' => $this->parentModelId,
            'relationship' => $this->relationshipName,
            'error' => $exception->getMessage(),
        ]);
    }
}
