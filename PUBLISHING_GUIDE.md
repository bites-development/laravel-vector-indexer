# Publishing Guide

## Overview

The Laravel Vector Indexer package provides publishable assets that you can customize for your application.

## What Can Be Published?

### 1. Configuration File
- **Tag:** `vector-indexer-config`
- **Source:** `packages/bites/laravel-vector-indexer/config/vector-indexer.php`
- **Destination:** `config/vector-indexer.php`
- **Purpose:** Customize OpenAI, Qdrant, chunking, and queue settings

### 2. Migrations
- **Tag:** `vector-indexer-migrations`
- **Source:** `packages/bites/laravel-vector-indexer/database/migrations/`
- **Destination:** `database/migrations/`
- **Purpose:** Modify database schema if needed

## Publishing Commands

### Publish Config Only

```bash
php artisan vendor:publish --tag=vector-indexer-config
```

**Result:**
```
Copying file [packages/.../config/vector-indexer.php] to [config/vector-indexer.php]
```

### Publish Migrations Only

```bash
php artisan vendor:publish --tag=vector-indexer-migrations
```

**Result:**
```
Copying directory [packages/.../database/migrations] to [database/migrations]
```

### Publish Everything

```bash
php artisan vendor:publish --provider="Bites\VectorIndexer\Providers\VectorIndexerServiceProvider"
```

**Result:**
- Config file published
- All migrations published

### Force Re-publish

```bash
# Force overwrite existing files
php artisan vendor:publish --tag=vector-indexer-config --force
php artisan vendor:publish --tag=vector-indexer-migrations --force
```

## Do You Need to Publish?

### ✅ You DON'T Need to Publish If:

- Using default OpenAI model (`text-embedding-3-large`)
- Using default Qdrant settings
- Using default chunking settings (1000 chars, 100 overlap)
- Using default queue name (`vector-indexing`)
- Happy with default database schema

**The package works out-of-the-box without publishing!**

### 📝 You SHOULD Publish Config If:

- Want to use a different OpenAI model
- Need custom embedding dimensions
- Want to adjust chunking parameters
- Need different queue settings
- Want to customize auto-indexing behavior

### 🗄️ You SHOULD Publish Migrations If:

- Need to add custom columns to vector tables
- Want to modify indexes
- Need to adjust table names
- Want to add custom constraints

## Configuration Options

### After Publishing Config

Edit `config/vector-indexer.php`:

```php
return [
    // OpenAI Settings
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'dimensions' => 3072, // Adjust based on model
        'timeout' => 30,
    ],

    // Qdrant Settings
    'qdrant' => [
        'host' => env('QDRANT_HOST', 'http://localhost:6333'),
        'api_key' => env('QDRANT_API_KEY'),
        'timeout' => 30,
    ],

    // Chunking Settings
    'chunking' => [
        'max_chunk_size' => 1000,
        'overlap' => 100,
        'respect_sentences' => true,
    ],

    // Queue Settings
    'queue' => [
        'enabled' => env('VECTOR_QUEUE_ENABLED', true),
        'queue_name' => env('VECTOR_QUEUE_NAME', 'vector-indexing'),
    ],

    // Auto-indexing Settings
    'auto_indexing' => [
        'on_create' => true,
        'on_update' => true,
        'on_delete' => true,
        'debounce_seconds' => 5,
    ],
];
```

## Migration Customization

### After Publishing Migrations

You can modify the published migrations in `database/migrations/`:

```php
// Example: Add custom column to vector_configurations
Schema::table('vector_configurations', function (Blueprint $table) {
    $table->string('custom_field')->nullable();
});
```

**Important:** If you modify migrations after running them, you'll need to:

```bash
# Rollback
php artisan migrate:rollback --step=4

# Re-run with modifications
php artisan migrate
```

## Environment Variables

After publishing config, you can control settings via `.env`:

```env
# OpenAI
OPENAI_API_KEY=sk-...
OPENAI_EMBEDDING_MODEL=text-embedding-3-large

# Qdrant
QDRANT_HOST=http://localhost:6333
QDRANT_API_KEY=your-api-key

# Queue
VECTOR_QUEUE_ENABLED=true
VECTOR_QUEUE_NAME=vector-indexing
```

## Published Files Location

### Config
```
config/
└── vector-indexer.php          ← Published config
```

### Migrations
```
database/migrations/
├── 2025_11_19_000001_create_vector_configurations_table.php
├── 2025_11_19_000002_create_vector_index_queue_table.php
├── 2025_11_19_000003_create_vector_index_logs_table.php
└── 2025_11_19_000004_create_vector_relationship_watchers_table.php
```

## Updating Published Files

### When Package Updates

If you've published files and the package updates:

1. **Config File:**
   ```bash
   # Review changes in package
   diff config/vector-indexer.php packages/bites/laravel-vector-indexer/config/vector-indexer.php
   
   # Re-publish if needed
   php artisan vendor:publish --tag=vector-indexer-config --force
   ```

2. **Migrations:**
   - Package migrations auto-load, no need to re-publish
   - Only re-publish if you need new migrations

## Best Practices

### 1. Version Control

```gitignore
# .gitignore

# Don't ignore published config (team needs it)
# config/vector-indexer.php

# Don't ignore published migrations
# database/migrations/*_create_vector_*.php
```

### 2. Environment-Specific Settings

Use `.env` for environment-specific values:

```php
// config/vector-indexer.php
'openai' => [
    'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
],
```

```env
# .env.local
OPENAI_EMBEDDING_MODEL=text-embedding-3-small

# .env.production
OPENAI_EMBEDDING_MODEL=text-embedding-3-large
```

### 3. Keep Package Defaults

Only publish if you need to customize. Package defaults work for most use cases.

### 4. Document Changes

If you modify published files, document why:

```php
// config/vector-indexer.php

// Custom: Using smaller model for development
'openai' => [
    'model' => 'text-embedding-3-small', // Changed from default
],
```

## Troubleshooting

### Config Not Taking Effect

```bash
# Clear config cache
php artisan config:clear

# Or cache new config
php artisan config:cache
```

### Migrations Already Exist

```bash
# If you get "table already exists" error
php artisan migrate:status

# Check if migrations already ran
# If yes, no need to re-publish
```

### Want to Reset to Defaults

```bash
# Delete published config
rm config/vector-indexer.php

# Package will use defaults from vendor/
```

## Summary

✅ **Publishing is optional** - Package works without it  
✅ **Publish config** to customize settings  
✅ **Publish migrations** to modify schema  
✅ **Use .env** for environment-specific values  
✅ **Version control** published files  
✅ **Document** any customizations  

Most users won't need to publish anything! 🎉
