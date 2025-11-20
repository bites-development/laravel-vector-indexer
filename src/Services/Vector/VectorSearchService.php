<?php

namespace Bites\VectorIndexer\Services\Vector;

use Bites\VectorIndexer\Models\VectorConfiguration;
use Bites\VectorIndexer\Services\Vector\Drivers\QdrantDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VectorSearchService
{
    protected EmbeddingService $embeddingService;
    protected QdrantDriver $driver;

    public function __construct()
    {
        $this->embeddingService = app(EmbeddingService::class);
        $this->driver = app(QdrantDriver::class);
    }

    /**
     * Search for models using natural language query
     */
    public function search(
        string $modelClass,
        string $query,
        int $limit = 20,
        float $threshold = 0.3,
        array $filters = []
    ): Collection {
        $config = VectorConfiguration::where('model_class', $modelClass)
            ->where('enabled', true)
            ->first();

        if (!$config) {
            throw new \RuntimeException("No configuration found for {$modelClass}");
        }

        // Generate query embedding
        $queryEmbedding = $this->embeddingService->embedSingle($query);

        // Search in Qdrant
        $results = $this->driver->search(
            $config->collection_name,
            $queryEmbedding,
            $limit * 3, // Get more results for deduplication
            $threshold,
            $filters
        );

        // Extract unique model IDs with best scores
        $modelScores = [];
        foreach ($results as $result) {
            $modelId = $result['payload']['model_id'] ?? null;
            $score = $result['score'] ?? 0;

            if ($modelId && (!isset($modelScores[$modelId]) || $score > $modelScores[$modelId])) {
                $modelScores[$modelId] = $score;
            }
        }

        // Sort by score and limit
        arsort($modelScores);
        $modelScores = array_slice($modelScores, 0, $limit, true);

        // Load models
        $models = $modelClass::whereIn('id', array_keys($modelScores))->get();

        // Attach scores and sort
        return $models->map(function ($model) use ($modelScores) {
            $model->relevance_score = $modelScores[$model->id] ?? 0;
            return $model;
        })->sortByDesc('relevance_score')->values();
    }

    /**
     * Search and return only IDs
     */
    public function searchIds(
        string $modelClass,
        string $query,
        int $limit = 20,
        float $threshold = 0.3,
        array $filters = []
    ): array {
        $results = $this->search($modelClass, $query, $limit, $threshold, $filters);
        return $results->pluck('id')->toArray();
    }

    /**
     * Search with scores
     */
    public function searchWithScores(
        string $modelClass,
        string $query,
        int $limit = 20,
        float $threshold = 0.3,
        array $filters = []
    ): array {
        $results = $this->search($modelClass, $query, $limit, $threshold, $filters);
        
        return $results->map(function ($model) {
            return [
                'model' => $model,
                'score' => $model->relevance_score ?? 0,
            ];
        })->toArray();
    }

    /**
     * Find similar models to a given model
     */
    public function findSimilar(
        $model,
        int $limit = 10,
        float $threshold = 0.5
    ): Collection {
        $modelClass = get_class($model);
        
        $config = VectorConfiguration::where('model_class', $modelClass)
            ->where('enabled', true)
            ->first();

        if (!$config) {
            throw new \RuntimeException("No configuration found for {$modelClass}");
        }

        // Get the model's vectors from Qdrant
        $results = $this->driver->search(
            $config->collection_name,
            [], // We'll use the model's existing vectors
            $limit + 1, // +1 to exclude self
            $threshold,
            ['model_id' => $model->id]
        );

        // Extract similar model IDs (excluding self)
        $similarIds = [];
        foreach ($results as $result) {
            $modelId = $result['payload']['model_id'] ?? null;
            if ($modelId && $modelId != $model->id) {
                $similarIds[] = $modelId;
            }
        }

        return $modelClass::whereIn('id', $similarIds)->get();
    }

    /**
     * Get search statistics
     */
    public function getStats(string $modelClass): array
    {
        $config = VectorConfiguration::where('model_class', $modelClass)->first();

        if (!$config) {
            return [];
        }

        $collectionInfo = $this->driver->getCollectionInfo($config->collection_name);

        return [
            'model' => $modelClass,
            'collection' => $config->collection_name,
            'indexed_count' => $config->indexed_count,
            'pending_count' => $config->pending_count,
            'failed_count' => $config->failed_count,
            'last_indexed' => $config->last_indexed_at?->toDateTimeString(),
            'vector_count' => $collectionInfo['points_count'] ?? 0,
            'indexed_vectors' => $collectionInfo['indexed_vectors_count'] ?? 0,
        ];
    }
}
