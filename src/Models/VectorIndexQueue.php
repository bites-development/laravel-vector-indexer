<?php

namespace Bites\VectorIndexer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VectorIndexQueue extends Model
{
    protected $table = 'vector_index_queue';

    protected $fillable = [
        'vector_configuration_id',
        'model_class',
        'model_id',
        'action',
        'related_changes',
        'triggered_by',
        'status',
        'attempts',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'related_changes' => 'array',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];

    /**
     * Get the configuration for this queue item
     */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(VectorConfiguration::class, 'vector_configuration_id');
    }

    /**
     * Mark as processing
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'attempts' => $this->attempts + 1,
        ]);
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'processed_at' => now(),
        ]);
    }

    /**
     * Check if should retry
     */
    public function shouldRetry(int $maxAttempts = 3): bool
    {
        return $this->attempts < $maxAttempts;
    }

    /**
     * Scope to get pending items
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get processing items
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope to get failed items
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to get completed items
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for specific model
     */
    public function scopeForModel($query, string $modelClass, int $modelId)
    {
        return $query->where('model_class', $modelClass)
                     ->where('model_id', $modelId);
    }

    /**
     * Scope for specific action
     */
    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }
}
