<?php

namespace Bites\VectorIndexer\Console\Commands;

use Bites\VectorIndexer\Models\VectorConfiguration;
use Bites\VectorIndexer\Jobs\Vector\IndexModelJob;
use Illuminate\Console\Command;

class IndexModelCommand extends Command
{
    protected $signature = 'vector:index 
                            {model : The model class to index}
                            {--batch=100 : Batch size for processing}
                            {--queue : Process via queue instead of synchronously}
                            {--ids= : Comma-separated list of specific IDs to index}
                            {--limit= : Limit number of records to index}
                            {--force : Re-index even if already indexed}';

    protected $description = 'Index existing records for a model';

    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $batchSize = (int) $this->option('batch');
        $useQueue = $this->option('queue');
        $specificIds = $this->option('ids');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $force = $this->option('force');

        $this->info("📦 Indexing Model: {$modelClass}");
        $this->newLine();

        try {
            // Load configuration
            $config = VectorConfiguration::where('model_class', $modelClass)
                ->where('enabled', true)
                ->first();
            
            if (!$config) {
                $this->error("❌ No enabled configuration found for {$modelClass}");
                $this->comment("   Run: php artisan vector:generate-config {$modelClass}");
                return self::FAILURE;
            }

            // Build query
            $query = $modelClass::query();
            
            if ($specificIds) {
                $ids = array_map('intval', explode(',', $specificIds));
                $query->whereIn('id', $ids);
            }
            
            if ($limit) {
                $query->limit($limit);
            }

            $totalCount = $query->count();
            
            if ($totalCount === 0) {
                $this->warn("⚠️  No records found to index");
                return self::SUCCESS;
            }

            $this->info("Found {$totalCount} records to index");
            $this->newLine();

            if (!$this->confirm("Proceed with indexing?", true)) {
                $this->info("Indexing cancelled");
                return self::SUCCESS;
            }

            // Process records
            $bar = $this->output->createProgressBar($totalCount);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
            
            $processed = 0;
            $failed = 0;

            $query->chunk($batchSize, function ($records) use ($config, $useQueue, $bar, $force, &$processed, &$failed) {
                foreach ($records as $record) {
                    try {
                        // Skip if already has a pending/processing queue item (unless force)
                        if (!$force) {
                            $existingQueue = \App\Models\VectorIndexQueue::where('vector_configuration_id', $config->id)
                                ->where('model_class', get_class($record))
                                ->where('model_id', $record->id)
                                ->whereIn('status', ['pending', 'processing'])
                                ->exists();
                            
                            if ($existingQueue) {
                                $bar->advance();
                                continue;
                            }
                        }
                        
                        if ($useQueue) {
                            // Create queue item and dispatch
                            \App\Models\VectorIndexQueue::create([
                                'vector_configuration_id' => $config->id,
                                'model_class' => get_class($record),
                                'model_id' => $record->id,
                                'action' => 'index',
                                'status' => 'pending',
                            ]);
                            
                            dispatch(new IndexModelJob(
                                $config->id,
                                get_class($record),
                                $record->id,
                                'index'
                            ))->onQueue(config('vector-indexer.queue.queue_name', 'vector-indexing'));
                        } else {
                            // Create queue item for synchronous processing
                            \App\Models\VectorIndexQueue::create([
                                'vector_configuration_id' => $config->id,
                                'model_class' => get_class($record),
                                'model_id' => $record->id,
                                'action' => 'index',
                                'status' => 'pending',
                            ]);
                            
                            // Process synchronously
                            $job = new IndexModelJob(
                                $config->id,
                                get_class($record),
                                $record->id,
                                'index'
                            );
                            $job->handle();
                            
                            // Update stats for synchronous processing
                            $config->incrementIndexed();
                        }
                        
                        $processed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->newLine();
                        $this->error("Failed to index record {$record->id}: {$e->getMessage()}");
                    }
                    
                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine(2);

            // Display summary
            $this->displaySummary($processed, $failed, $useQueue);

            return $failed > 0 ? self::FAILURE : self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("❌ Indexing failed: {$e->getMessage()}");
            
            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }

    protected function displaySummary(int $processed, int $failed, bool $useQueue): void
    {
        $this->info("✨ Indexing Summary:");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Processed', $processed],
                ['Successful', $processed - $failed],
                ['Failed', $failed],
                ['Processing Mode', $useQueue ? 'Queued' : 'Synchronous'],
            ]
        );

        if ($useQueue) {
            $this->newLine();
            $this->comment("   Jobs have been queued. Monitor with: php artisan queue:work");
            $this->comment("   Check status with: php artisan vector:status");
        }
    }
}
