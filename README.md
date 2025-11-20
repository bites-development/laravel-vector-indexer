# Laravel Vector Indexer

Automatic vector indexing and semantic search for Laravel models using OpenAI embeddings and Qdrant vector database.

## Features

- 🤖 **Automatic Model Analysis** - Analyzes your models and suggests optimal indexing configuration
- 🔄 **Smart Indexing** - Prevents N+1 queries with intelligent eager loading
- 🔗 **Relationship Support** - Indexes related data with unlimited depth and circular reference prevention
- 🔍 **Semantic Search** - Natural language search with relevance scoring
- ⚡ **Queue Support** - Async indexing via Laravel queues
- 🎯 **Real-time Updates** - Auto-indexes on model create/update/delete
- 📊 **Status Monitoring** - Track indexing progress and stats
- 🛡️ **Duplicate Prevention** - Smart deduplication at queue and vector levels

## Installation

### 1. Install via Composer

```bash
composer require bites/laravel-vector-indexer
```

### 2. Publish Config (Optional)

The package config has sensible defaults. Only publish if you need to customize settings:

```bash
# Publish config (optional)
php artisan vendor:publish --tag=vector-indexer-config
```

**Note:** Migrations are auto-loaded from the package. Do NOT publish them unless you need to modify the schema.

### 3. Run Migrations

```bash
php artisan migrate
```

### 4. Configure Environment

Add to your `.env`:

```env
# OpenAI Configuration
OPENAI_API_KEY=your-openai-api-key
OPENAI_EMBEDDING_MODEL=text-embedding-3-large

# Qdrant Configuration
QDRANT_HOST=http://localhost:6333
QDRANT_API_KEY=your-qdrant-api-key  # Optional

# Queue Configuration (optional)
VECTOR_QUEUE_NAME=vector-indexing
```

## Quick Start

### 1. Add Traits to Your Model

```php
use Bites\VectorIndexer\Traits\Vectorizable;
use Bites\VectorIndexer\Traits\HasVectorSearch;

class Post extends Model
{
    use Vectorizable, HasVectorSearch;
    
    // Your model code...
}
```

### 2. Generate Configuration

```bash
# Analyze model and generate config
php artisan vector:analyze "App\Models\Post"
php artisan vector:generate-config "App\Models\Post"
```

### 3. Start Watching for Changes

```bash
# Enable auto-indexing on model changes
php artisan vector:watch "App\Models\Post"
```

### 4. Index Existing Records

```bash
# Index all existing records
php artisan vector:index "App\Models\Post"

# Or with queue
php artisan vector:index "App\Models\Post" --queue
```

### 5. Search!

```php
// Simple search
$posts = Post::vectorSearch("Laravel best practices");

// With filters
$posts = Post::vectorSearch("Laravel", filters: [
    'status' => 'published',
    'author_id' => 123
]);

// With limit
$posts = Post::vectorSearch("Laravel", limit: 5);

// Find similar
$similar = $post->findSimilar(limit: 10);
```

## Commands

### Analyze Model
```bash
php artisan vector:analyze "App\Models\Post"
```
Analyzes model structure and suggests configuration.

### Generate Configuration
```bash
php artisan vector:generate-config "App\Models\Post"
```
Creates vector indexing configuration for the model.

### Watch Model
```bash
php artisan vector:watch "App\Models\Post"
```
Enables auto-indexing on model changes.

### Unwatch Model
```bash
php artisan vector:unwatch "App\Models\Post"
```
Disables auto-indexing.

### Index Records
```bash
# Synchronous
php artisan vector:index "App\Models\Post"

# With queue
php artisan vector:index "App\Models\Post" --queue

# Specific IDs
php artisan vector:index "App\Models\Post" --ids=1,2,3

# Force re-index
php artisan vector:index "App\Models\Post" --force
```

### Check Status
```bash
php artisan vector:status "App\Models\Post"
```

## Configuration

The `config/vector-indexer.php` file contains all configuration options:

```php
return [
    // OpenAI settings
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'dimensions' => 3072,
    ],

    // Qdrant settings
    'qdrant' => [
        'host' => env('QDRANT_HOST', 'http://localhost:6333'),
        'api_key' => env('QDRANT_API_KEY'),
    ],

    // Queue settings
    'queue' => [
        'enabled' => env('VECTOR_QUEUE_ENABLED', true),
        'queue_name' => env('VECTOR_QUEUE_NAME', 'vector-indexing'),
    ],

    // Chunking settings
    'chunking' => [
        'max_chunk_size' => 1000,
        'overlap' => 100,
    ],
];
```

## Advanced Usage

### Custom Field Weights

```php
// In your VectorConfiguration
'fields' => [
    'title' => ['weight' => 3],      // Higher weight = more important
    'content' => ['weight' => 1],
    'excerpt' => ['weight' => 2],
]
```

### Relationship Indexing

The package automatically indexes relationships:

```php
// Post model with relationships
public function author() { return $this->belongsTo(User::class); }
public function tags() { return $this->belongsToMany(Tag::class); }
public function comments() { return $this->hasMany(Comment::class); }

// All relationships are automatically indexed!
```

### Search with Filters

```php
$posts = Post::vectorSearch("Laravel tutorials", filters: [
    'status' => 'published',
    'author_id' => $userId,
    'created_at' => ['gte' => now()->subDays(30)]
]);
```

### Batch Processing

```php
// Process in batches
php artisan vector:index "App\Models\Post" --batch=50
```

## Queue Configuration

For production, configure Laravel Horizon:

```php
// config/horizon.php
'vector-supervisor' => [
    'connection' => 'redis',
    'queue' => ['vector-indexing'],
    'balance' => 'auto',
    'maxProcesses' => 5,
    'memory' => 256,
    'timeout' => 300,
    'tries' => 3,
],
```

## Testing

```bash
composer test
```

## Requirements

- PHP 8.1+
- Laravel 10.0+
- OpenAI API Key
- Qdrant instance (local or cloud)

## License

MIT

## Credits

Developed by [Bites Team](https://bites.app)

## Support

For issues and questions, please use [GitHub Issues](https://github.com/bites/laravel-vector-indexer/issues).
