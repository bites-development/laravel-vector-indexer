<?php

namespace Bites\VectorIndexer\Services\Vector\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QdrantDriver
{
    protected string $host;
    protected ?string $apiKey;
    protected int $timeout;

    public function __construct()
    {
        $this->host = rtrim(config('vector-indexer.drivers.qdrant.host', 'http://localhost:6333'), '/');
        $this->apiKey = config('vector-indexer.drivers.qdrant.api_key');
        $this->timeout = config('vector-indexer.drivers.qdrant.timeout', 30);
    }

    /**
     * Create or ensure collection exists
     */
    public function ensureCollection(string $collectionName, int $vectorSize): void
    {
        try {
            // Check if collection exists
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->getHeaders())
                ->get("{$this->host}/collections/{$collectionName}");

            if ($response->status() === 404) {
                // Create collection
                $this->createCollection($collectionName, $vectorSize);
            } else {
                Log::debug("Collection already exists", ['collection' => $collectionName]);
            }

            // Ensure field indexes
            $this->createFieldIndexes($collectionName);

        } catch (\Throwable $e) {
            Log::error("Failed to ensure collection", [
                'collection' => $collectionName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Create a new collection
     */
    protected function createCollection(string $collectionName, int $vectorSize): void
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders($this->getHeaders())
            ->put("{$this->host}/collections/{$collectionName}", [
                'vectors' => [
                    'size' => $vectorSize,
                    'distance' => 'Cosine',
                ],
                'on_disk' => true,
                'optimizers_config' => [
                    'indexing_threshold' => 0, // Index immediately
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Failed to create collection: {$response->status()} - {$response->body()}"
            );
        }

        Log::info("Created Qdrant collection", [
            'collection' => $collectionName,
            'vector_size' => $vectorSize,
        ]);
    }

    /**
     * Create field indexes for filtering
     */
    protected function createFieldIndexes(string $collectionName): void
    {
        // Common indexes for filtering
        $indexes = [
            ['field_name' => 'model_id', 'field_schema' => 'integer'],
            ['field_name' => 'account_id', 'field_schema' => 'integer'],
            ['field_name' => 'message_id', 'field_schema' => 'keyword'],
            ['field_name' => 'folder_name', 'field_schema' => 'keyword'],
        ];

        foreach ($indexes as $index) {
            try {
                Http::timeout($this->timeout)
                    ->withHeaders($this->getHeaders())
                    ->put("{$this->host}/collections/{$collectionName}/index", $index);
            } catch (\Throwable $e) {
                // Ignore if index already exists
                Log::debug("Could not create index", [
                    'field' => $index['field_name'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Upsert points (vectors) into collection
     */
    public function upsert(
        string $collectionName,
        array $points
    ): void {
        if (empty($points)) {
            return;
        }

        $response = Http::timeout($this->timeout)
            ->withHeaders($this->getHeaders())
            ->put("{$this->host}/collections/{$collectionName}/points", [
                'points' => $points,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Failed to upsert points: {$response->status()} - {$response->body()}"
            );
        }

        Log::debug("Upserted points to Qdrant", [
            'collection' => $collectionName,
            'count' => count($points),
        ]);
    }

    /**
     * Search for similar vectors
     */
    public function search(
        string $collectionName,
        array $vector,
        int $limit = 10,
        float $scoreThreshold = 0.3,
        array $filter = []
    ): array {
        $payload = [
            'vector' => $vector,
            'limit' => $limit,
            'score_threshold' => $scoreThreshold,
            'with_payload' => true,
            'with_vector' => false,
        ];

        if (!empty($filter)) {
            $payload['filter'] = $this->buildFilter($filter);
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->getHeaders())
                ->post("{$this->host}/collections/{$collectionName}/points/search", $payload);

            if (!$response->successful()) {
                throw new \RuntimeException(
                    "Search failed: {$response->status()} - {$response->body()}"
                );
            }

            $data = $response->json();
            return $data['result'] ?? [];

        } catch (\Throwable $e) {
            Log::error("Qdrant search failed", [
                'collection' => $collectionName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Delete points by filter
     */
    public function delete(string $collectionName, array $filter): void
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders($this->getHeaders())
            ->post("{$this->host}/collections/{$collectionName}/points/delete", [
                'filter' => $this->buildFilter($filter),
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Failed to delete points: {$response->status()} - {$response->body()}"
            );
        }

        Log::info("Deleted points from Qdrant", [
            'collection' => $collectionName,
            'filter' => $filter,
        ]);
    }

    /**
     * Alias for delete method
     */
    public function deleteByFilter(string $collectionName, array $filter): void
    {
        $this->delete($collectionName, $filter);
    }

    /**
     * Delete points by IDs
     */
    public function deleteByIds(string $collectionName, array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $response = Http::timeout($this->timeout)
            ->withHeaders($this->getHeaders())
            ->post("{$this->host}/collections/{$collectionName}/points/delete", [
                'points' => $ids,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Failed to delete points: {$response->status()} - {$response->body()}"
            );
        }

        Log::info("Deleted points from Qdrant", [
            'collection' => $collectionName,
            'count' => count($ids),
        ]);
    }

    /**
     * Get collection info
     */
    public function getCollectionInfo(string $collectionName): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->getHeaders())
                ->get("{$this->host}/collections/{$collectionName}");

            if ($response->successful()) {
                return $response->json()['result'] ?? null;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Test connection
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->getHeaders())
                ->get("{$this->host}/collections");

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error("Qdrant connection test failed", [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Build Qdrant filter from array
     */
    protected function buildFilter(array $filter): array
    {
        $must = [];

        foreach ($filter as $key => $value) {
            if ($value === null) {
                continue;
            }

            $must[] = [
                'key' => $key,
                'match' => ['value' => $value],
            ];
        }

        return ['must' => $must];
    }

    /**
     * Get request headers
     */
    protected function getHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($this->apiKey) {
            $headers['api-key'] = $this->apiKey;
        }

        return $headers;
    }

    /**
     * Get host URL
     */
    public function getHost(): string
    {
        return $this->host;
    }
}
