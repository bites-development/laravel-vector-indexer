# Changelog

All notable changes to `laravel-vector-indexer` will be documented in this file.

## v1.0.0 - 2025-01-19

### Added
- Initial release
- Automatic model analysis and configuration generation
- Smart indexing with N+1 prevention via eager loading
- Unlimited relationship depth with circular reference prevention
- OpenAI embeddings integration (text-embedding-3-large)
- Qdrant vector database driver
- Semantic search with relevance scoring
- Queue support for async indexing
- Real-time auto-indexing on model changes
- Duplicate prevention at queue and vector levels
- CLI commands:
  - `vector:analyze` - Analyze model structure
  - `vector:generate-config` - Generate indexing configuration
  - `vector:watch` - Enable auto-indexing
  - `vector:unwatch` - Disable auto-indexing
  - `vector:index` - Index existing records
  - `vector:status` - Monitor indexing progress
- Traits:
  - `Vectorizable` - Makes model indexable
  - `HasVectorSearch` - Adds search capabilities
- Services:
  - `ModelAnalyzer` - Analyzes model structure
  - `SchemaAnalyzer` - Analyzes database schema
  - `RelationshipAnalyzer` - Analyzes model relationships
  - `DataLoaderService` - Loads model data with eager loading
  - `EmbeddingService` - Generates OpenAI embeddings
  - `ChunkingService` - Smart text chunking
  - `VectorSearchService` - Semantic search
  - `QdrantDriver` - Qdrant database integration
- Models:
  - `VectorConfiguration` - Stores indexing config
  - `VectorIndexQueue` - Manages indexing queue
  - `VectorIndexLog` - Tracks indexing history
  - `VectorRelationshipWatcher` - Monitors relationships
- Jobs:
  - `IndexModelJob` - Handles model indexing
  - `ReindexRelatedJob` - Reindexes related models
- Support for Laravel 9.x, 10.x, 11.x
- Support for PHP 8.1, 8.2, 8.3
