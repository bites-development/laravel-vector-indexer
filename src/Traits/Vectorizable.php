<?php

namespace Bites\VectorIndexer\Traits;

use Bites\VectorIndexer\Models\VectorConfiguration;

trait Vectorizable
{
    /**
     * Boot the trait
     */
    public static function bootVectorizable(): void
    {
        // Traits can hook into model events here if needed
    }

    /**
     * Get the vector configuration for this model
     */
    public function getVectorConfiguration(): ?VectorConfiguration
    {
        return VectorConfiguration::where('model_class', static::class)
            ->first();
    }

    /**
     * Check if this model is configured for vector indexing
     */
    public function isVectorized(): bool
    {
        return $this->getVectorConfiguration() !== null;
    }

    /**
     * Check if vector indexing is enabled
     */
    public function isVectorIndexingEnabled(): bool
    {
        $config = $this->getVectorConfiguration();
        return $config && $config->enabled;
    }

    /**
     * Get the vector collection name
     */
    public function getVectorCollectionName(): ?string
    {
        return $this->getVectorConfiguration()?->collection_name;
    }

    /**
     * Manually trigger indexing for this model
     */
    public function indexVector(): void
    {
        $config = $this->getVectorConfiguration();

        if (!$config) {
            throw new \RuntimeException("No vector configuration found for " . static::class);
        }

        dispatch(new \App\Jobs\Vector\IndexModelJob(
            $config->id,
            static::class,
            $this->id,
            'index'
        ));
    }

    /**
     * Manually trigger reindexing for this model
     */
    public function reindexVector(): void
    {
        $config = $this->getVectorConfiguration();

        if (!$config) {
            throw new \RuntimeException("No vector configuration found for " . static::class);
        }

        dispatch(new \App\Jobs\Vector\IndexModelJob(
            $config->id,
            static::class,
            $this->id,
            'update'
        ));
    }

    /**
     * Delete this model's vectors
     */
    public function deleteVector(): void
    {
        $config = $this->getVectorConfiguration();

        if (!$config) {
            return;
        }

        dispatch(new \App\Jobs\Vector\IndexModelJob(
            $config->id,
            static::class,
            $this->id,
            'delete'
        ));
    }

    /**
     * Get vector indexing statistics for this model
     */
    public function getVectorStats(): array
    {
        $config = $this->getVectorConfiguration();

        if (!$config) {
            return [];
        }

        return [
            'indexed' => $config->indexed_count,
            'pending' => $config->pending_count,
            'failed' => $config->failed_count,
            'last_indexed' => $config->last_indexed_at,
        ];
    }
}
