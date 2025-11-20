<?php

namespace Bites\VectorIndexer\Console\Commands;

use Bites\VectorIndexer\Models\VectorConfiguration;
use Bites\VectorIndexer\Models\VectorIndexQueue;
use Bites\VectorIndexer\Models\VectorIndexLog;
use Illuminate\Console\Command;

class VectorStatusCommand extends Command
{
    protected $signature = 'vector:status 
                            {model? : Specific model to check (optional)}
                            {--detailed : Show detailed statistics}';

    protected $description = 'Show vector indexing status';

    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $detailed = $this->option('detailed');

        $this->info("📊 Vector Indexing Status");
        $this->newLine();

        try {
            if ($modelClass) {
                $this->showModelStatus($modelClass, $detailed);
            } else {
                $this->showAllStatus($detailed);
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("❌ Failed to get status: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function showAllStatus(bool $detailed): void
    {
        $configurations = VectorConfiguration::with('watchers')->get();

        if ($configurations->isEmpty()) {
            $this->warn("⚠️  No vector configurations found");
            $this->comment("   Run: php artisan vector:analyze <model>");
            return;
        }

        foreach ($configurations as $config) {
            $this->displayConfigStatus($config, $detailed);
            $this->newLine();
        }

        // Overall statistics
        $this->displayOverallStats($configurations);
    }

    protected function showModelStatus(string $modelClass, bool $detailed): void
    {
        $config = VectorConfiguration::where('model_class', $modelClass)
            ->with('watchers')
            ->first();

        if (!$config) {
            $this->error("❌ No configuration found for {$modelClass}");
            return;
        }

        $this->displayConfigStatus($config, $detailed);
    }

    protected function displayConfigStatus(VectorConfiguration $config, bool $detailed): void
    {
        $status = $config->enabled ? '<fg=green>✓ Enabled</>' : '<fg=red>✗ Disabled</>';
        
        $this->line("<fg=cyan>{$config->model_class}</> {$status}");
        $this->line(str_repeat('─', 60));

        // Basic stats
        $this->table(
            ['Metric', 'Value'],
            [
                ['Collection', $config->collection_name],
                ['Driver', $config->driver],
                ['Indexed', number_format($config->indexed_count)],
                ['Pending', number_format($config->pending_count)],
                ['Failed', number_format($config->failed_count)],
                ['Last Indexed', $config->last_indexed_at?->diffForHumans() ?? 'Never'],
            ]
        );

        if ($detailed) {
            $this->displayDetailedStats($config);
        }
    }

    protected function displayDetailedStats(VectorConfiguration $config): void
    {
        $this->newLine();
        
        // Queue status
        $queueStats = VectorIndexQueue::where('vector_configuration_id', $config->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        if ($queueStats->isNotEmpty()) {
            $this->info("📋 Queue Status:");
            foreach ($queueStats as $status => $count) {
                $icon = match($status) {
                    'pending' => '⏳',
                    'processing' => '⚙️',
                    'completed' => '✓',
                    'failed' => '✗',
                    default => '•'
                };
                $this->line("  {$icon} {$status}: " . number_format($count));
            }
        }

        // Recent logs
        $recentLogs = VectorIndexLog::where('vector_configuration_id', $config->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        if ($recentLogs->isNotEmpty()) {
            $this->newLine();
            $this->info("📝 Recent Activity:");
            foreach ($recentLogs as $log) {
                $icon = $log->status === 'success' ? '✓' : '✗';
                $time = $log->created_at->diffForHumans();
                $this->line("  {$icon} {$log->action} - {$time}");
            }
        }

        // Relationship watchers
        $watchers = $config->watchers()->enabled()->get();
        if ($watchers->isNotEmpty()) {
            $this->newLine();
            $this->info("🔗 Active Watchers: {$watchers->count()}");
            foreach ($watchers as $watcher) {
                $this->line("  • {$watcher->relationship_path}");
            }
        }
    }

    protected function displayOverallStats($configurations): void
    {
        $this->info("📈 Overall Statistics:");
        
        $totalIndexed = $configurations->sum('indexed_count');
        $totalPending = $configurations->sum('pending_count');
        $totalFailed = $configurations->sum('failed_count');
        $enabledCount = $configurations->where('enabled', true)->count();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Configurations', $configurations->count()],
                ['Enabled', $enabledCount],
                ['Total Indexed', number_format($totalIndexed)],
                ['Total Pending', number_format($totalPending)],
                ['Total Failed', number_format($totalFailed)],
            ]
        );
    }
}
