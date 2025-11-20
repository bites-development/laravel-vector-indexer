<?php

namespace Bites\VectorIndexer\Providers;

use Illuminate\Support\ServiceProvider;
use Bites\VectorIndexer\Console\Commands\{
    AnalyzeModelCommand,
    GenerateConfigCommand,
    IndexModelCommand,
    VectorStatusCommand,
    WatchModelCommand,
    UnwatchModelCommand
};
use Bites\VectorIndexer\Services\Vector\{
    ModelAnalyzer,
    SchemaAnalyzer,
    RelationshipAnalyzer,
    DataLoaderService,
    EmbeddingService,
    ChunkingService,
    VectorSearchService
};
use Bites\VectorIndexer\Services\Vector\Drivers\QdrantDriver;

class VectorIndexerServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/vector-indexer.php',
            'vector-indexer'
        );

        // Register services
        $this->app->singleton(ModelAnalyzer::class);
        $this->app->singleton(SchemaAnalyzer::class);
        $this->app->singleton(RelationshipAnalyzer::class);
        $this->app->singleton(DataLoaderService::class);
        $this->app->singleton(EmbeddingService::class);
        $this->app->singleton(ChunkingService::class);
        $this->app->singleton(VectorSearchService::class);
        $this->app->singleton(QdrantDriver::class);
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../../config/vector-indexer.php' => config_path('vector-indexer.php'),
        ], 'vector-indexer-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../../database/migrations' => database_path('migrations'),
        ], 'vector-indexer-migrations');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                AnalyzeModelCommand::class,
                GenerateConfigCommand::class,
                IndexModelCommand::class,
                VectorStatusCommand::class,
                WatchModelCommand::class,
                UnwatchModelCommand::class,
            ]);
        }
    }
}
