<?php

namespace Bites\VectorIndexer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VectorIndexLog extends Model
{
    protected $fillable = [
        'vector_configuration_id',
        'model_class',
        'model_id',
        'action',
        'records_processed',
        'chunks_created',
        'embeddings_generated',
        'duration_seconds',
        'status',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'records_processed' => 'integer',
        'chunks_created' => 'integer',
        'embeddings_generated' => 'integer',
        'duration_seconds' => 'float',
        'metadata' => 'array',
    ];

    /**
     * Get the configuration for this log
     */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(VectorConfiguration::class, 'vector_configuration_id');
    }

    /**
     * Create a success log
     */
    public static function logSuccess(
        int $configId,
        string $modelClass,
        ?int $modelId,
        string $action,
        int $recordsProcessed = 1,
        ?int $chunksCreated = null,
        ?int $embeddingsGenerated = null,
        ?float $durationSeconds = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'vector_configuration_id' => $configId,
            'model_class' => $modelClass,
            'model_id' => $modelId,
            'action' => $action,
            'records_processed' => $recordsProcessed,
            'chunks_created' => $chunksCreated,
            'embeddings_generated' => $embeddingsGenerated,
            'duration_seconds' => $durationSeconds,
            'status' => 'success',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Create a failure log
     */
    public static function logFailure(
        int $configId,
        string $modelClass,
        ?int $modelId,
        string $action,
        string $errorMessage,
        ?array $metadata = null
    ): self {
        return self::create([
            'vector_configuration_id' => $configId,
            'model_class' => $modelClass,
            'model_id' => $modelId,
            'action' => $action,
            'status' => 'failed',
            'error_message' => $errorMessage,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Scope to get successful logs
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope to get failed logs
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope for specific model
     */
    public function scopeForModel($query, string $modelClass)
    {
        return $query->where('model_class', $modelClass);
    }

    /**
     * Scope for specific action
     */
    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope for recent logs
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }
}
