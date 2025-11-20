<?php

namespace Bites\VectorIndexer\Jobs\Vector;

use Bites\VectorIndexer\Models\VectorConfiguration;
use Bites\VectorIndexer\Models\VectorIndexQueue;
use Bites\VectorIndexer\Models\VectorIndexLog;
use Bites\VectorIndexer\Services\Vector\DataLoaderService;
use Bites\VectorIndexer\Services\Vector\EmbeddingService;
use Bites\VectorIndexer\Services\Vector\ChunkingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes
    public int $backoff = 60; // 1 minute between retries

    protected int $configId;
    protected string $modelClass;
    protected int $modelId;
    protected string $action;

    /**
     * Create a new job instance
     */
    public function __construct(
        int $configId,
        string $modelClass,
        int $modelId,
        string $action
    ) {
        $this->configId = $configId;
        $this->modelClass = $modelClass;
        $this->modelId = $modelId;
        $this->action = $action;
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        
        try {
            // Load configuration
            $config = VectorConfiguration::findOrFail($this->configId);
            
            // Find queue item (optional - may not exist for manual indexing)
            $queueItem = VectorIndexQueue::where('vector_configuration_id', $this->configId)
                ->where('model_class', $this->modelClass)
                ->where('model_id', $this->modelId)
                ->where('action', $this->action)
                ->where('status', 'pending')
                ->first();
            
            // Mark as processing if queue item exists
            if ($queueItem) {
                $queueItem->markAsProcessing();
            }
            
            // Handle based on action
            $result = match($this->action) {
                'index', 'update' => $this->indexModel($config),
                'delete' => $this->deleteModel($config),
                default => throw new \InvalidArgumentException("Unknown action: {$this->action}")
            };
            
            $duration = microtime(true) - $startTime;
            
            // Mark as completed if queue item exists
            if ($queueItem) {
                $queueItem->markAsCompleted();
                $config->decrementPending();
            }
            $config->incrementIndexed();
            
            // Log success
            VectorIndexLog::logSuccess(
                $this->configId,
                $this->modelClass,
                $this->modelId,
                $this->action,
                1,
                $result['chunks_created'] ?? null,
                $result['embeddings_generated'] ?? null,
                $duration,
                $result['metadata'] ?? null
            );
            
            Log::info("Vector indexing completed", [
                'model' => $this->modelClass,
                'id' => $this->modelId,
                'action' => $this->action,
                'duration' => round($duration, 2),
            ]);
            
        } catch (\Throwable $e) {
            $this->handleFailure($e);
        }
    }

    /**
     * Index or update a model
     */
    protected function indexModel(VectorConfiguration $config): array
    {
        // Load model
        $model = $this->modelClass::findOrFail($this->modelId);
        
        // Load services
        $dataLoader = app(DataLoaderService::class);
        $embeddingService = app(EmbeddingService::class);
        $chunkingService = app(ChunkingService::class);
        
        // Load model with relationships (N+1 prevention!)
        $model = $dataLoader->loadWithRelationships($model, $config);
        
        // Extract content
        $content = $dataLoader->extractContent($model, $config);
        
        if (empty($content)) {
            Log::warning("No content to index", [
                'model' => $this->modelClass,
                'id' => $this->modelId,
            ]);
            return ['chunks_created' => 0, 'embeddings_generated' => 0];
        }
        
        // Process content into chunks
        $chunks = [];
        foreach ($content as $item) {
            if ($item['chunk']) {
                // Chunk large text
                $textChunks = $chunkingService->chunk(
                    $item['value'],
                    $item['chunk_size'] ?? 1000,
                    $item['chunk_overlap'] ?? 200
                );
                
                foreach ($textChunks as $chunkText) {
                    $chunks[] = [
                        'text' => $chunkText,
                        'weight' => $item['weight'],
                        'source' => $item['source'],
                        'field' => $item['field'],
                    ];
                }
            } else {
                // Use as-is
                $chunks[] = [
                    'text' => $item['value'],
                    'weight' => $item['weight'],
                    'source' => $item['source'],
                    'field' => $item['field'],
                ];
            }
        }
        
        // Generate embeddings
        $texts = array_column($chunks, 'text');
        $embeddings = $embeddingService->embed($texts);
        
        // Extract metadata
        $metadata = $dataLoader->extractMetadata($model, $config);
        
        // Store in vector DB (this will be implemented with drivers)
        $this->storeInVectorDB($config, $model, $chunks, $embeddings, $metadata);
        
        return [
            'chunks_created' => count($chunks),
            'embeddings_generated' => count($embeddings),
            'metadata' => [
                'content_size' => array_sum(array_map('strlen', $texts)),
                'sources' => array_unique(array_column($chunks, 'source')),
            ],
        ];
    }

    /**
     * Delete model from vector DB
     */
    protected function deleteModel(VectorConfiguration $config): array
    {
        $driver = app(\App\Services\Vector\Drivers\QdrantDriver::class);
        
        // Delete all points for this model
        $driver->delete($config->collection_name, [
            'model_class' => $this->modelClass,
            'model_id' => $this->modelId,
        ]);
        
        Log::info("Deleted from Qdrant", [
            'model' => $this->modelClass,
            'id' => $this->modelId,
            'collection' => $config->collection_name,
        ]);
        
        return ['chunks_created' => 0, 'embeddings_generated' => 0];
    }

    /**
     * Store in vector database
     */
    protected function storeInVectorDB(
        VectorConfiguration $config,
        $model,
        array $chunks,
        array $embeddings,
        array $metadata
    ): void {
        $driver = app(\App\Services\Vector\Drivers\QdrantDriver::class);
        
        // Ensure collection exists
        $vectorSize = $config->getEmbeddingDimensions();
        $driver->ensureCollection($config->collection_name, $vectorSize);
        
        // Delete existing vectors for this model to prevent duplicates
        // This handles cases where chunk count changes
        try {
            $driver->deleteByFilter($config->collection_name, [
                'model_class' => $config->model_class,
                'model_id' => $model->id,
            ]);
        } catch (\Throwable $e) {
            // If delete fails (e.g., missing index), log but continue
            // Upsert will handle duplicates
            Log::debug("Could not delete existing vectors, will upsert instead", [
                'model' => $config->model_class,
                'id' => $model->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Prepare points for Qdrant
        $points = [];
        foreach ($chunks as $index => $chunk) {
            if (!isset($embeddings[$index])) {
                continue;
            }
            
            $pointId = md5($config->model_class . ':' . $model->id . ':' . $index);
            
            $points[] = [
                'id' => $pointId,
                'vector' => $embeddings[$index],
                'payload' => array_merge($metadata, [
                    'model_class' => $config->model_class,
                    'model_id' => $model->id,
                    'chunk_index' => $index,
                    'source' => $chunk['source'],
                    'field' => $chunk['field'],
                    'weight' => $chunk['weight'],
                    'text_preview' => substr($chunk['text'], 0, 200),
                ]),
            ];
        }
        
        // Upsert to Qdrant
        $driver->upsert($config->collection_name, $points);
        
        Log::info("Stored in Qdrant", [
            'collection' => $config->collection_name,
            'points' => count($points),
        ]);
    }

    /**
     * Handle job failure
     */
    protected function handleFailure(\Throwable $e): void
    {
        $queueItem = VectorIndexQueue::where('vector_configuration_id', $this->configId)
            ->where('model_class', $this->modelClass)
            ->where('model_id', $this->modelId)
            ->where('action', $this->action)
            ->first();
        
        if ($queueItem) {
            if ($queueItem->shouldRetry($this->tries)) {
                Log::warning("Vector indexing failed, will retry", [
                    'model' => $this->modelClass,
                    'id' => $this->modelId,
                    'attempt' => $queueItem->attempts,
                    'error' => $e->getMessage(),
                ]);
            } else {
                $queueItem->markAsFailed($e->getMessage());
                
                $config = VectorConfiguration::find($this->configId);
                if ($config) {
                    $config->decrementPending();
                    $config->incrementFailed();
                }
                
                VectorIndexLog::logFailure(
                    $this->configId,
                    $this->modelClass,
                    $this->modelId,
                    $this->action,
                    $e->getMessage()
                );
                
                Log::error("Vector indexing failed permanently", [
                    'model' => $this->modelClass,
                    'id' => $this->modelId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
        
        throw $e;
    }

    /**
     * Handle job failure event
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Vector indexing job failed", [
            'model' => $this->modelClass,
            'id' => $this->modelId,
            'action' => $this->action,
            'error' => $exception->getMessage(),
        ]);
    }
}
