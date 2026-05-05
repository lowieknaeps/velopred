<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\TrainingDatasetService;
use Illuminate\Console\Command;

class BuildTrainingExamplesCommand extends Command
{
    protected $signature = 'predict:build-training-examples
                            {year : Year to backfill}
                            {--type= : Only build for one prediction_type (result|stage|gc|points|kom|youth)}
                            {--slug= : Only build for one race pcs_slug}
                            {--limit= : Limit number of races (for quick tests)}';

    protected $description = 'Backfill training_examples from existing predictions + race_results in DB.';

    public function handle(TrainingDatasetService $trainingDataset): int
    {
        $year = (int) $this->argument('year');

        $query = Race::query()->where('year', $year);

        if ($this->option('slug') !== null) {
            $query->where('pcs_slug', (string) $this->option('slug'));
        }

        $query->orderBy('start_date');

        if ($this->option('limit') !== null) {
            $query->limit((int) $this->option('limit'));
        }

        $races = $query->get();

        if ($races->isEmpty()) {
            $this->error("No races found for {$year}.");
            return self::FAILURE;
        }

        $onlyType = $this->option('type') !== null ? (string) $this->option('type') : null;

        $this->info("🧩 Building training examples for {$races->count()} race(s)...");

        $totalSaved = 0;
        foreach ($races as $race) {
            $race->refresh();

            if ($onlyType !== null) {
                // Build only contexts of this type that exist in results.
                $contexts = $race->results()
                    ->select('result_type', 'stage_number')
                    ->distinct()
                    ->where('result_type', $onlyType)
                    ->get()
                    ->map(fn ($row) => [
                        'type' => (string) $row->result_type,
                        'stage' => (int) ($row->stage_number ?? 0),
                    ]);

                $saved = 0;
                foreach ($contexts as $ctx) {
                    $saved += $trainingDataset->persistRaceContext($race, $ctx['type'], $ctx['stage']);
                }
            } else {
                $saved = $trainingDataset->persistAllAvailableContexts($race);
            }

            $totalSaved += $saved;

            $this->line(sprintf("→ %s (%s): %d example(s)", $race->name, $race->pcs_slug, $saved));
        }

        $this->info("✅ Done. Saved/updated {$totalSaved} training example(s).");

        return self::SUCCESS;
    }
}

