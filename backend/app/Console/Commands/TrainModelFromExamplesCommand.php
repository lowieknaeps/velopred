<?php

namespace App\Console\Commands;

use App\Models\TrainingExample;
use App\Services\ExternalCyclingApiService;
use Illuminate\Console\Command;

class TrainModelFromExamplesCommand extends Command
{
    protected $signature = 'predict:train-examples
                            {--year= : Only use examples for a given year}
                            {--type= : Only use a prediction_type (result|stage|gc|points|kom|youth)}
                            {--limit= : Limit number of examples (for quick tests)}';

    protected $description = 'Train AI model from stored training_examples (MySQL-backed).';

    public function handle(ExternalCyclingApiService $api): int
    {
        $query = TrainingExample::query();

        if ($this->option('year') !== null) {
            $query->where('race_year', (int) $this->option('year'));
        }
        if ($this->option('type') !== null) {
            $query->where('prediction_type', (string) $this->option('type'));
        }
        if ($this->option('limit') !== null) {
            $query->limit((int) $this->option('limit'));
        }

        $this->info('📦 Loading training examples...');
        $rows = $query->orderBy('evaluated_at')->get();

        if ($rows->isEmpty()) {
            $this->error('No training examples found.');
            return self::FAILURE;
        }

        $examples = $rows->map(function (TrainingExample $ex) {
            $features = is_array($ex->features) ? $ex->features : (json_decode((string) $ex->features, true) ?: []);

            // Flatten label/meta into the feature row for the trainer.
            return array_merge($features, [
                'actual_position' => $ex->actual_position,
                'actual_status' => $ex->actual_status,
                'race_year' => (int) $ex->race_year,
                'parcours_type' => (string) ($ex->parcours_type ?? 'default'),
                // category_weight is used by the trainer. Keep a safe default if missing.
                'category_weight' => 1.0,
            ]);
        })->all();

        $this->info(sprintf('🧠 Training model from %d examples...', count($examples)));

        try {
            $stats = $api->trainFromExamples($examples);
            $this->info("✅ Model trained — version: " . ($stats['model_version'] ?? 'unknown'));
            if (!empty($stats['mae_cv'])) {
                $this->info("MAE (cv): " . $stats['mae_cv']);
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Training failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}

