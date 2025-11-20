<?php

namespace Bites\VectorIndexer\Console\Commands;

use Bites\VectorIndexer\Services\Vector\ModelAnalyzer;
use Illuminate\Console\Command;

class AnalyzeModelCommand extends Command
{
    protected $signature = 'vector:analyze 
                            {model : The model class to analyze (e.g., App\\Models\\EmailCache)}
                            {--depth=3 : Maximum relationship depth to analyze}
                            {--generate : Generate configuration after analysis}';

    protected $description = 'Analyze a model for vector indexing suitability';

    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $depth = (int) $this->option('depth');
        $shouldGenerate = $this->option('generate');

        $this->info("🔍 Analyzing Model: {$modelClass}");
        $this->newLine();

        try {
            $analyzer = app(ModelAnalyzer::class);
            
            // Check if model is suitable
            if (!$analyzer->isSuitableForIndexing($modelClass)) {
                $this->error("❌ Model is not suitable for vector indexing (no text fields found)");
                return self::FAILURE;
            }

            // Perform analysis
            $analysis = $analyzer->analyze($modelClass, $depth);

            // Display results
            $this->displayAnalysis($analysis);

            // Ask to generate configuration
            if ($shouldGenerate || $this->confirm('Generate configuration for this model?', true)) {
                $this->call('vector:generate-config', [
                    'model' => $modelClass,
                ]);
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("❌ Analysis failed: {$e->getMessage()}");
            $this->newLine();
            
            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }

    protected function displayAnalysis(array $analysis): void
    {
        // Basic info
        $this->table(
            ['Property', 'Value'],
            [
                ['Model Class', $analysis['model_class']],
                ['Table', $analysis['table']],
                ['Total Columns', $analysis['total_columns']],
                ['Text Fields', count($analysis['text_fields'])],
                ['Metadata Fields', count($analysis['metadata_fields'])],
                ['Relationships', count($analysis['relationships'])],
                ['Eager Load Paths', count($analysis['eager_load_map'])],
            ]
        );

        $this->newLine();

        // Text fields
        if (!empty($analysis['text_fields'])) {
            $this->info("📝 Text Fields (will be embedded):");
            $rows = [];
            foreach ($analysis['text_fields'] as $field => $info) {
                $rows[] = [
                    $field,
                    $info['type'],
                    $info['weight'],
                    $info['chunk'] ? '✓' : '✗',
                    $info['chunk'] ? $info['chunk_size'] : 'N/A',
                ];
            }
            $this->table(
                ['Field', 'Type', 'Weight', 'Chunk', 'Chunk Size'],
                $rows
            );
            $this->newLine();
        }

        // Metadata fields
        if (!empty($analysis['metadata_fields'])) {
            $this->info("📊 Metadata Fields (stored but not embedded):");
            $this->line('  ' . implode(', ', $analysis['metadata_fields']));
            $this->newLine();
        }

        // Relationships
        if (!empty($analysis['relationships'])) {
            $this->info("🔗 Relationships (will be watched):");
            $rows = [];
            foreach ($analysis['relationships'] as $path => $rel) {
                $rows[] = [
                    $path,
                    $rel['type'],
                    class_basename($rel['model']),
                    $rel['depth'],
                    count($rel['fields']),
                ];
            }
            $this->table(
                ['Path', 'Type', 'Model', 'Depth', 'Text Fields'],
                $rows
            );
            $this->newLine();
        }

        // Eager load map
        if (!empty($analysis['eager_load_map'])) {
            $this->info("⚡ Eager Load Map (N+1 Prevention):");
            foreach ($analysis['eager_load_map'] as $path) {
                $this->line("  • {$path}");
            }
            $this->newLine();
        }

        // Recommendations
        if (!empty($analysis['recommendations'])) {
            $this->info("💡 Recommendations:");
            foreach ($analysis['recommendations'] as $rec) {
                $icon = match($rec['type']) {
                    'success' => '✓',
                    'warning' => '⚠',
                    'info' => 'ℹ',
                    default => '•'
                };
                
                $style = match($rec['type']) {
                    'success' => 'info',
                    'warning' => 'comment',
                    'error' => 'error',
                    default => 'line'
                };
                
                $this->$style("  {$icon} {$rec['message']}");
            }
            $this->newLine();
        }

        // Suggested configuration preview
        $this->info("⚙️  Suggested Configuration:");
        $config = $analysis['suggested_config'];
        $this->table(
            ['Setting', 'Value'],
            [
                ['Collection Name', $config['collection_name']],
                ['Driver', $config['driver']],
                ['Fields to Index', count($config['fields'])],
                ['Relationships', count($config['relationships'])],
                ['Max Depth', $config['max_relationship_depth']],
                ['Embedding Model', $config['options']['embedding_model']],
                ['Dimensions', $config['options']['embedding_dimensions']],
            ]
        );
    }
}
