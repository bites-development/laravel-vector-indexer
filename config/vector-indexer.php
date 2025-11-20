<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Vector Database Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default vector database driver that will be used
    | for storing and searching embeddings. Supported: "qdrant", "pinecone"
    |
    */

    'default_driver' => env('VECTOR_DB_DRIVER', 'qdrant'),

    /*
    |--------------------------------------------------------------------------
    | Vector Database Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection settings for each vector database
    | driver supported by the application.
    |
    */

    'drivers' => [
        'qdrant' => [
            'host' => env('QDRANT_HOST', 'http://localhost:6333'),
            'api_key' => env('QDRANT_API_KEY'),
            'timeout' => env('QDRANT_TIMEOUT', 30),
        ],

        'pinecone' => [
            'api_key' => env('PINECONE_API_KEY'),
            'environment' => env('PINECONE_ENVIRONMENT', 'us-west1-gcp'),
            'timeout' => env('PINECONE_TIMEOUT', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for OpenAI embeddings API.
    |
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'embedding_dimensions' => env('OPENAI_EMBEDDING_DIMENSIONS', 3072),
        'timeout' => env('OPENAI_TIMEOUT', 30),
        'max_retries' => env('OPENAI_MAX_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunking Configuration
    |--------------------------------------------------------------------------
    |
    | Default settings for text chunking when processing large text fields.
    |
    */

    'chunking' => [
        'enabled' => env('VECTOR_CHUNKING_ENABLED', true),
        'chunk_size' => env('VECTOR_CHUNK_SIZE', 1000),
        'chunk_overlap' => env('VECTOR_CHUNK_OVERLAP', 200),
        'min_chunk_size' => env('VECTOR_MIN_CHUNK_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for queue-based indexing operations.
    |
    */

    'queue' => [
        'enabled' => env('VECTOR_QUEUE_ENABLED', true),
        'connection' => env('VECTOR_QUEUE_CONNECTION', 'redis'),
        'queue_name' => env('VECTOR_QUEUE_NAME', 'vector-indexing'),
        'batch_size' => env('VECTOR_BATCH_SIZE', 100),
        'max_attempts' => env('VECTOR_MAX_ATTEMPTS', 3),
        'retry_after' => env('VECTOR_RETRY_AFTER', 60), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Relationship Watching
    |--------------------------------------------------------------------------
    |
    | Configuration for automatic relationship watching and reindexing.
    |
    */

    'relationships' => [
        'enabled' => env('VECTOR_WATCH_RELATIONSHIPS', true),
        'max_depth' => env('VECTOR_MAX_RELATIONSHIP_DEPTH', 3),
        'auto_register_observers' => env('VECTOR_AUTO_REGISTER_OBSERVERS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance & Optimization
    |--------------------------------------------------------------------------
    |
    | Settings to optimize performance and resource usage.
    |
    */

    'performance' => [
        'eager_loading' => true, // Always use eager loading to prevent N+1
        'cache_embeddings' => env('VECTOR_CACHE_EMBEDDINGS', false),
        'cache_ttl' => env('VECTOR_CACHE_TTL', 86400), // 24 hours
        'batch_embedding' => true, // Batch multiple texts in single API call
        'max_batch_size' => 100, // Maximum texts per batch
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Control logging behavior for vector operations.
    |
    */

    'logging' => [
        'enabled' => env('VECTOR_LOGGING_ENABLED', true),
        'log_successful_operations' => env('VECTOR_LOG_SUCCESS', true),
        'log_failed_operations' => env('VECTOR_LOG_FAILURES', true),
        'log_performance_metrics' => env('VECTOR_LOG_METRICS', true),
        'retention_days' => env('VECTOR_LOG_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Field Weights
    |--------------------------------------------------------------------------
    |
    | Default weights for different field types when auto-generating configs.
    |
    */

    'default_weights' => [
        'title' => 2.0,
        'subject' => 1.5,
        'name' => 1.5,
        'body' => 1.0,
        'content' => 1.0,
        'description' => 1.0,
        'summary' => 0.8,
        'notes' => 0.7,
        'tags' => 0.5,
        'keywords' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Indexing Configuration
    |--------------------------------------------------------------------------
    |
    | Control automatic indexing behavior when models are created/updated.
    |
    */

    'auto_indexing' => [
        'enabled' => env('VECTOR_AUTO_INDEXING', true),
        'on_create' => env('VECTOR_INDEX_ON_CREATE', true),
        'on_update' => env('VECTOR_INDEX_ON_UPDATE', true),
        'on_delete' => env('VECTOR_DELETE_ON_DELETE', true),
        'debounce_seconds' => env('VECTOR_DEBOUNCE_SECONDS', 5), // Prevent rapid reindexing
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Configuration
    |--------------------------------------------------------------------------
    |
    | Default settings for vector search operations.
    |
    */

    'search' => [
        'default_limit' => env('VECTOR_SEARCH_LIMIT', 20),
        'default_threshold' => env('VECTOR_SEARCH_THRESHOLD', 0.3),
        'max_limit' => env('VECTOR_SEARCH_MAX_LIMIT', 100),
        'include_score' => env('VECTOR_SEARCH_INCLUDE_SCORE', true),
    ],

];
