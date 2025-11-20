<?php

namespace Bites\VectorIndexer\Traits;

use Bites\VectorIndexer\Services\Vector\VectorSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

trait HasVectorSearch
{
    /**
     * Perform a vector search
     */
    public static function vectorSearch(
        string $query,
        int $limit = 20,
        float $threshold = 0.3,
        array $filters = []
    ): Collection {
        $searchService = app(VectorSearchService::class);
        
        return $searchService->search(
            static::class,
            $query,
            $limit,
            $threshold,
            $filters
        );
    }

    /**
     * Search and return only IDs
     */
    public static function vectorSearchIds(
        string $query,
        int $limit = 20,
        float $threshold = 0.3,
        array $filters = []
    ): array {
        $searchService = app(VectorSearchService::class);
        
        return $searchService->searchIds(
            static::class,
            $query,
            $limit,
            $threshold,
            $filters
        );
    }

    /**
     * Search with scores
     */
    public static function vectorSearchWithScores(
        string $query,
        int $limit = 20,
        float $threshold = 0.3,
        array $filters = []
    ): array {
        $searchService = app(VectorSearchService::class);
        
        return $searchService->searchWithScores(
            static::class,
            $query,
            $limit,
            $threshold,
            $filters
        );
    }

    /**
     * Find similar models to this one
     */
    public function findSimilar(int $limit = 10, float $threshold = 0.5): Collection
    {
        $searchService = app(VectorSearchService::class);
        
        return $searchService->findSimilar($this, $limit, $threshold);
    }

    /**
     * Scope for vector search (chainable with Eloquent)
     */
    public function scopeVectorSearch(
        Builder $query,
        string $searchQuery,
        int $limit = 20,
        float $threshold = 0.3,
        array $filters = []
    ): Builder {
        $ids = static::vectorSearchIds($searchQuery, $limit, $threshold, $filters);
        
        if (empty($ids)) {
            // Return empty result
            return $query->whereRaw('1 = 0');
        }
        
        // Order by relevance (maintain vector search order)
        $idsString = implode(',', $ids);
        return $query->whereIn('id', $ids)
                     ->orderByRaw("FIELD(id, {$idsString})");
    }

    /**
     * Get vector search statistics
     */
    public static function getVectorSearchStats(): array
    {
        $searchService = app(VectorSearchService::class);
        return $searchService->getStats(static::class);
    }
}
