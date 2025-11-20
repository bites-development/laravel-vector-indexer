<?php

namespace Bites\VectorIndexer\Console\Commands;

use Bites\VectorIndexer\Models\VectorConfiguration;
use Bites\VectorIndexer\Services\Vector\VectorObserverManager;
use Illuminate\Console\Command;

class WatchModelCommand extends Command
{
    protected $signature = 'vector:watch 
                            {model : The model class to watch}';

    protected $description = 'Start watching a model for automatic vector indexing';

    public function handle(): int
    {
        $modelClass = $this->argument('model');

        $this->info("👀 Starting to watch: {$modelClass}");
        $this->newLine();

        try {
            // Check if configuration exists
            $config = VectorConfiguration::where('model_class', $modelClass)->first();
            
            if (!$config) {
                $this->error("❌ No configuration found for {$modelClass}");
                $this->comment("   Run: php artisan vector:generate-config {$modelClass}");
                return self::FAILURE;
            }

            // Enable configuration if disabled
            if (!$config->enabled) {
                $config->update(['enabled' => true]);
                $this->info("✓ Configuration enabled");
            }

            // Register observers
            $observerManager = app(VectorObserverManager::class);
            $observerManager->registerObservers($modelClass);

            $this->info("✓ Observers registered for {$modelClass}");
            $this->newLine();

            // Display what's being watched
            $this->displayWatchInfo($config);

            $this->newLine();
            $this->info("✨ Model is now being watched!");
            $this->comment("   Changes to this model will be automatically indexed.");
            $this->newLine();

            // Show next steps
            $this->info("📝 Next Steps:");
            $this->line("  1. Index existing records: php artisan vector:index {$modelClass}");
            $this->line("  2. Check status: php artisan vector:status {$modelClass}");
            $this->line("  3. Stop watching: php artisan vector:unwatch {$modelClass}");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("❌ Failed to start watching: {$e->getMessage()}");
            
            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }

    protected function displayWatchInfo(VectorConfiguration $config): void
    {
        $this->info("📊 Watch Configuration:");
        
        // Main model
        $this->table(
            ['Property', 'Value'],
            [
                ['Model', $config->model_class],
                ['Collection', $config->collection_name],
                ['Fields Watched', count($config->fields)],
                ['Auto-index on Create', config('vector-indexer.auto_indexing.on_create') ? '✓' : '✗'],
                ['Auto-index on Update', config('vector-indexer.auto_indexing.on_update') ? '✓' : '✗'],
                ['Auto-delete on Delete', config('vector-indexer.auto_indexing.on_delete') ? '✓' : '✗'],
                ['Debounce', config('vector-indexer.auto_indexing.debounce_seconds') . 's'],
            ]
        );

        // Watched fields
        $this->newLine();
        $this->info("📝 Watched Fields:");
        foreach ($config->fields as $field => $fieldConfig) {
            $weight = $fieldConfig['weight'] ?? 1.0;
            $chunk = $fieldConfig['chunk'] ?? false;
            $this->line("  • {$field} (weight: {$weight}" . ($chunk ? ', chunked' : '') . ')');
        }

        // Relationship watchers
        $watchers = $config->watchers()->enabled()->get();
        if ($watchers->isNotEmpty()) {
            $this->newLine();
            $this->info("🔗 Relationship Watchers:");
            foreach ($watchers as $watcher) {
                $this->line("  • {$watcher->relationship_path} ({$watcher->relationship_type})");
            }
        }
    }
}
