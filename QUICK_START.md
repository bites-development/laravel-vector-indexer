# Quick Start Guide

## Installation

The package is already installed as a local package. For new projects:

```bash
composer require bites/laravel-vector-indexer
```

## Setup (5 Minutes)

### 1. Configure Environment

```env
OPENAI_API_KEY=your-openai-api-key
QDRANT_HOST=http://localhost:6333
QDRANT_API_KEY=your-qdrant-api-key  # Optional
```

### 2. Run Migrations

```bash
php artisan migrate
```

### 3. Add Traits to Model

```php
use Bites\VectorIndexer\Traits\Vectorizable;
use Bites\VectorIndexer\Traits\HasVectorSearch;

class Post extends Model
{
    use Vectorizable, HasVectorSearch;
}
```

### 4. Generate Configuration

```bash
php artisan vector:generate-config "App\Models\Post"
```

### 5. Enable Auto-Indexing

```bash
php artisan vector:watch "App\Models\Post"
```

### 6. Index Existing Records

```bash
php artisan vector:index "App\Models\Post"
```

## Usage

### Basic Search

```php
$posts = Post::vectorSearch("Laravel best practices");
```

### Search with Filters

```php
$posts = Post::vectorSearch("Laravel", filters: [
    'status' => 'published',
    'author_id' => 123
]);
```

### Find Similar

```php
$similar = $post->findSimilar(limit: 10);
```

### With Limit

```php
$posts = Post::vectorSearch("Laravel", limit: 5);
```

## Commands

```bash
# Analyze model structure
php artisan vector:analyze "App\Models\Post"

# Generate configuration
php artisan vector:generate-config "App\Models\Post"

# Enable auto-indexing
php artisan vector:watch "App\Models\Post"

# Disable auto-indexing
php artisan vector:unwatch "App\Models\Post"

# Index records
php artisan vector:index "App\Models\Post"
php artisan vector:index "App\Models\Post" --queue
php artisan vector:index "App\Models\Post" --force

# Check status
php artisan vector:status "App\Models\Post"
```

## Configuration

Publish config (optional):

```bash
php artisan vendor:publish --tag=vector-indexer-config
```

Edit `config/vector-indexer.php`:

```php
return [
    'openai' => [
        'model' => 'text-embedding-3-large',
        'dimensions' => 3072,
    ],
    'chunking' => [
        'max_chunk_size' => 1000,
        'overlap' => 100,
    ],
];
```

## Queue Setup (Production)

Configure Horizon in `config/horizon.php`:

```php
'vector-supervisor' => [
    'connection' => 'redis',
    'queue' => ['vector-indexing'],
    'maxProcesses' => 5,
    'memory' => 256,
    'timeout' => 300,
],
```

Start Horizon:

```bash
php artisan horizon
```

## That's It! 🎉

Your models now have semantic search capabilities powered by OpenAI and Qdrant.
