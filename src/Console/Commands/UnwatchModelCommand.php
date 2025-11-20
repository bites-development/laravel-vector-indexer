<?php

namespace Bites\VectorIndexer\Console\Commands;

use Bites\VectorIndexer\Models\VectorConfiguration;
use Bites\VectorIndexer\Services\Vector\VectorObserverManager;
use Illuminate\Console\Command;

class UnwatchModelCommand extends Command
{
    protected $signature = 'vector:unwatch 
                            {model : The model class to stop watching}';

    protected $description = 'Stop watching a model for automatic vector indexing';

    public function handle(): int
    {
        $modelClass = $this->argument('model');

        $this->info("🛑 Stopping watch for: {$modelClass}");
        $this->newLine();

        try {
            // Check if configuration exists
            $config = VectorConfiguration::where('model_class', $modelClass)->first();
            
            if (!$config) {
                $this->error("❌ No configuration found for {$modelClass}");
                return self::FAILURE;
            }

            if (!$config->enabled) {
                $this->warn("⚠️  Model is already not being watched");
                return self::SUCCESS;
            }

            // Confirm action
            if (!$this->confirm("Stop watching {$modelClass}?", true)) {
                $this->info("Operation cancelled");
                return self::SUCCESS;
            }

            // Unregister observers
            $observerManager = app(VectorObserverManager::class);
            $observerManager->unregisterObservers($modelClass);

            $this->info("✓ Observers unregistered");
            $this->info("✓ Configuration disabled");
            $this->newLine();

            $this->info("✨ Model is no longer being watched");
            $this->comment("   Existing indexed data remains in vector database");
            $this->comment("   To resume watching: php artisan vector:watch {$modelClass}");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("❌ Failed to stop watching: {$e->getMessage()}");
            
            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }
}
