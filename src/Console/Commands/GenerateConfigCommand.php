<?php

namespace Bites\VectorIndexer\Console\Commands;

use Bites\VectorIndexer\Models\VectorConfiguration;
use Bites\VectorIndexer\Models\VectorRelationshipWatcher;
use Bites\VectorIndexer\Services\Vector\ModelAnalyzer;
use Illuminate\Console\Command;

class GenerateConfigCommand extends Command
{
    protected $signature = 'vector:generate-config 
                            {model : The model class to configure}
                            {--force : Overwrite existing configuration}
                            {--depth=3 : Maximum relationship depth}';

    protected $description = 'Generate vector indexing configuration for a model';

    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $force = $this->option('force');
        $depth = (int) $this->option('depth');

        $this->info("⚙️  Generating Configuration for: {$modelClass}");
        $this->newLine();

        try {
            // Check if configuration already exists
            $existing = VectorConfiguration::where('model_class', $modelClass)->first();
            
            if ($existing && !$force) {
                $this->warn("⚠️  Configuration already exists for this model.");
                
                if (!$this->confirm('Overwrite existing configuration?', false)) {
                    $this->info("Configuration generation cancelled.");
                    return self::SUCCESS;
                }
            }

            // Analyze model
            $analyzer = app(ModelAnalyzer::class);
            $analysis = $analyzer->analyze($modelClass, $depth);
            $suggestedConfig = $analysis['suggested_config'];

            // Create or update configuration
            $config = VectorConfiguration::updateOrCreate(
                ['model_class' => $modelClass],
                [
                    'collection_name' => $suggestedConfig['collection_name'],
                    'driver' => $suggestedConfig['driver'],
                    'enabled' => $suggestedConfig['enabled'],
                    'fields' => $suggestedConfig['fields'],
                    'metadata_fields' => $suggestedConfig['metadata_fields'],
                    'filters' => $suggestedConfig['filters'],
                    'relationships' => $suggestedConfig['relationships'],
                    'eager_load_map' => $suggestedConfig['eager_load_map'],
                    'max_relationship_depth' => $suggestedConfig['max_relationship_depth'],
                    'options' => $suggestedConfig['options'],
                ]
            );

            $this->info("✓ Configuration created/updated (ID: {$config->id})");
            $this->newLine();

            // Create relationship watchers
            $this->createRelationshipWatchers($config, $analysis['relationships']);

            // Display summary
            $this->displaySummary($config);

            // Ask to start watching
            if ($this->confirm('Start watching this model now?', true)) {
                $this->call('vector:watch', ['model' => $modelClass]);
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("❌ Configuration generation failed: {$e->getMessage()}");
            
            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }

    protected function createRelationshipWatchers(VectorConfiguration $config, array $relationships): void
    {
        if (empty($relationships)) {
            return;
        }

        $this->info("🔗 Creating relationship watchers...");

        // Delete existing watchers
        VectorRelationshipWatcher::where('vector_configuration_id', $config->id)->delete();

        $created = 0;
        foreach ($relationships as $path => $rel) {
            VectorRelationshipWatcher::create([
                'vector_configuration_id' => $config->id,
                'parent_model' => $rel['parent'],
                'related_model' => $rel['model'],
                'relationship_name' => $rel['name'],
                'relationship_type' => $rel['type'],
                'relationship_path' => $path,
                'depth' => $rel['depth'],
                'watch_fields' => $rel['fields'],
                'on_change_action' => 'reindex_parent',
                'enabled' => $rel['enabled'] ?? true,
            ]);
            $created++;
        }

        $this->info("✓ Created {$created} relationship watchers");
        $this->newLine();
    }

    protected function displaySummary(VectorConfiguration $config): void
    {
        $this->info("📋 Configuration Summary:");
        $this->table(
            ['Setting', 'Value'],
            [
                ['Model', $config->model_class],
                ['Collection', $config->collection_name],
                ['Driver', $config->driver],
                ['Enabled', $config->enabled ? '✓' : '✗'],
                ['Fields', count($config->fields)],
                ['Metadata Fields', count($config->metadata_fields ?? [])],
                ['Filters', count($config->filters ?? [])],
                ['Relationships', count($config->relationships ?? [])],
                ['Eager Load Paths', count($config->eager_load_map ?? [])],
                ['Max Depth', $config->max_relationship_depth],
            ]
        );
        $this->newLine();

        $this->info("✨ Configuration saved to database!");
        $this->comment("   You can now run: php artisan vector:watch {$config->model_class}");
    }
}
