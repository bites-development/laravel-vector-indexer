<?php

namespace Bites\VectorIndexer\Services\Vector;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    protected string $apiKey;
    protected string $model;
    protected int $dimensions;
    protected int $timeout;
    protected int $maxRetries;
    protected bool $cacheEnabled;
    protected int $cacheTtl;

    public function __construct()
    {
        $this->apiKey = config('vector-indexer.openai.api_key');
        $this->model = config('vector-indexer.openai.embedding_model', 'text-embedding-3-large');
        $this->dimensions = config('vector-indexer.openai.embedding_dimensions', 3072);
        $this->timeout = config('vector-indexer.openai.timeout', 30);
        $this->maxRetries = config('vector-indexer.openai.max_retries', 3);
        $this->cacheEnabled = config('vector-indexer.performance.cache_embeddings', false);
        $this->cacheTtl = config('vector-indexer.performance.cache_ttl', 86400);

        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key not configured. Set OPENAI_API_KEY in .env');
        }
    }

    /**
     * Generate embeddings for an array of texts
     * Supports batching for efficiency
     */
    public function embed(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        // Remove empty texts
        $texts = array_filter($texts, fn($text) => !empty(trim($text)));
        
        if (empty($texts)) {
            return [];
        }

        // Check cache first if enabled
        if ($this->cacheEnabled) {
            $cached = $this->getCachedEmbeddings($texts);
            if (!empty($cached)) {
                return $cached;
            }
        }

        // Generate embeddings
        $embeddings = $this->generateEmbeddings($texts);

        // Cache if enabled
        if ($this->cacheEnabled && !empty($embeddings)) {
            $this->cacheEmbeddings($texts, $embeddings);
        }

        return $embeddings;
    }

    /**
     * Generate embeddings via OpenAI API
     */
    protected function generateEmbeddings(array $texts): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $startTime = microtime(true);

                $response = Http::timeout($this->timeout)
                    ->withToken($this->apiKey)
                    ->post('https://api.openai.com/v1/embeddings', [
                        'model' => $this->model,
                        'input' => array_values($texts),
                        'dimensions' => $this->dimensions,
                    ]);

                if (!$response->successful()) {
                    throw new \RuntimeException(
                        "OpenAI API error: {$response->status()} - {$response->body()}"
                    );
                }

                $data = $response->json();
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                Log::info('OpenAI embeddings generated', [
                    'count' => count($texts),
                    'model' => $this->model,
                    'dimensions' => $this->dimensions,
                    'duration_ms' => $duration,
                    'attempt' => $attempt + 1,
                ]);

                // Extract embeddings from response
                $embeddings = array_map(
                    fn($item) => $item['embedding'],
                    $data['data']
                );

                return $embeddings;

            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;

                Log::warning('OpenAI embedding attempt failed', [
                    'attempt' => $attempt,
                    'max_retries' => $this->maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $this->maxRetries) {
                    // Exponential backoff
                    $waitSeconds = pow(2, $attempt);
                    sleep($waitSeconds);
                }
            }
        }

        // All retries failed
        Log::error('OpenAI embedding failed after all retries', [
            'attempts' => $this->maxRetries,
            'error' => $lastException->getMessage(),
        ]);

        throw new \RuntimeException(
            "Failed to generate embeddings after {$this->maxRetries} attempts: " . 
            $lastException->getMessage()
        );
    }

    /**
     * Generate embedding for a single text
     */
    public function embedSingle(string $text): array
    {
        $embeddings = $this->embed([$text]);
        return $embeddings[0] ?? [];
    }

    /**
     * Generate embeddings in batches
     */
    public function embedBatch(array $texts, int $batchSize = 100): array
    {
        $allEmbeddings = [];
        $batches = array_chunk($texts, $batchSize, true);

        foreach ($batches as $batch) {
            $embeddings = $this->embed($batch);
            $allEmbeddings = array_merge($allEmbeddings, $embeddings);
        }

        return $allEmbeddings;
    }

    /**
     * Get cached embeddings
     */
    protected function getCachedEmbeddings(array $texts): array
    {
        $embeddings = [];
        $allCached = true;

        foreach ($texts as $index => $text) {
            $cacheKey = $this->getCacheKey($text);
            $cached = Cache::get($cacheKey);

            if ($cached) {
                $embeddings[$index] = $cached;
            } else {
                $allCached = false;
                break;
            }
        }

        return $allCached ? $embeddings : [];
    }

    /**
     * Cache embeddings
     */
    protected function cacheEmbeddings(array $texts, array $embeddings): void
    {
        foreach ($texts as $index => $text) {
            if (isset($embeddings[$index])) {
                $cacheKey = $this->getCacheKey($text);
                Cache::put($cacheKey, $embeddings[$index], $this->cacheTtl);
            }
        }
    }

    /**
     * Generate cache key for text
     */
    protected function getCacheKey(string $text): string
    {
        $hash = md5($text . $this->model . $this->dimensions);
        return "vector_embedding:{$hash}";
    }

    /**
     * Clear embedding cache
     */
    public function clearCache(): void
    {
        // This would require a cache tag or pattern matching
        // For now, just log
        Log::info('Embedding cache clear requested');
    }

    /**
     * Get embedding statistics
     */
    public function getStats(): array
    {
        return [
            'model' => $this->model,
            'dimensions' => $this->dimensions,
            'cache_enabled' => $this->cacheEnabled,
            'cache_ttl' => $this->cacheTtl,
            'max_retries' => $this->maxRetries,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * Test OpenAI connection
     */
    public function testConnection(): bool
    {
        try {
            $this->embedSingle('test');
            return true;
        } catch (\Throwable $e) {
            Log::error('OpenAI connection test failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Calculate cosine similarity between two embeddings
     */
    public function cosineSimilarity(array $embedding1, array $embedding2): float
    {
        if (count($embedding1) !== count($embedding2)) {
            throw new \InvalidArgumentException('Embeddings must have the same dimensions');
        }

        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        for ($i = 0; $i < count($embedding1); $i++) {
            $dotProduct += $embedding1[$i] * $embedding2[$i];
            $magnitude1 += $embedding1[$i] * $embedding1[$i];
            $magnitude2 += $embedding2[$i] * $embedding2[$i];
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }

    /**
     * Estimate cost for embedding texts
     */
    public function estimateCost(array $texts): array
    {
        $totalTokens = 0;
        
        foreach ($texts as $text) {
            // Rough estimation: ~4 characters per token
            $totalTokens += (int) (strlen($text) / 4);
        }

        // text-embedding-3-large pricing: $0.00013 per 1K tokens
        $costPer1kTokens = 0.00013;
        $estimatedCost = ($totalTokens / 1000) * $costPer1kTokens;

        return [
            'total_texts' => count($texts),
            'estimated_tokens' => $totalTokens,
            'estimated_cost_usd' => round($estimatedCost, 4),
            'model' => $this->model,
        ];
    }
}
