<?php

namespace Bites\VectorIndexer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VectorConfiguration extends Model
{
    protected $fillable = [
        'model_class',
        'collection_name',
        'driver',
        'enabled',
        'fields',
        'metadata_fields',
        'filters',
        'relationships',
        'eager_load_map',
        'max_relationship_depth',
        'options',
        'indexed_count',
        'pending_count',
        'failed_count',
        'last_indexed_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'fields' => 'array',
        'metadata_fields' => 'array',
        'filters' => 'array',
        'relationships' => 'array',
        'eager_load_map' => 'array',
        'options' => 'array',
        'last_indexed_at' => 'datetime',
        'max_relationship_depth' => 'integer',
        'indexed_count' => 'integer',
        'pending_count' => 'integer',
        'failed_count' => 'integer',
    ];

    /**
     * Get queue items for this configuration
     */
    public function queueItems(): HasMany
    {
        return $this->hasMany(VectorIndexQueue::class);
    }

    /**
     * Get logs for this configuration
     */
    public function logs(): HasMany
    {
        return $this->hasMany(VectorIndexLog::class);
    }

    /**
     * Get relationship watchers for this configuration
     */
    public function watchers(): HasMany
    {
        return $this->hasMany(VectorRelationshipWatcher::class);
    }

    /**
     * Increment indexed count and update timestamp
     */
    public function incrementIndexed(): void
    {
        $this->increment('indexed_count');
        $this->update(['last_indexed_at' => now()]);
    }

    /**
     * Increment pending count
     */
    public function incrementPending(): void
    {
        $this->increment('pending_count');
    }

    /**
     * Decrement pending count
     */
    public function decrementPending(): void
    {
        $this->decrement('pending_count');
    }

    /**
     * Increment failed count
     */
    public function incrementFailed(): void
    {
        $this->increment('failed_count');
    }

    /**
     * Get an instance of the configured model
     */
    public function getModelInstance(): Model
    {
        return new $this->model_class;
    }

    /**
     * Check if a field should be embedded
     */
    public function shouldEmbedField(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    /**
     * Get field weight
     */
    public function getFieldWeight(string $field): float
    {
        return $this->fields[$field]['weight'] ?? 1.0;
    }

    /**
     * Check if field should be chunked
     */
    public function shouldChunkField(string $field): bool
    {
        return $this->fields[$field]['chunk'] ?? false;
    }

    /**
     * Get embedding model
     */
    public function getEmbeddingModel(): string
    {
        return $this->options['embedding_model'] ?? 'text-embedding-3-large';
    }

    /**
     * Get embedding dimensions
     */
    public function getEmbeddingDimensions(): int
    {
        return $this->options['embedding_dimensions'] ?? 3072;
    }

    /**
     * Scope to get enabled configurations
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to get configurations for a specific model
     */
    public function scopeForModel($query, string $modelClass)
    {
        return $query->where('model_class', $modelClass);
    }
}
