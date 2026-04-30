<?php

namespace App\Console\Commands;

use App\Models\Race;
use Illuminate\Console\Command;

class DebugRaceResultsCommand extends Command
{
    protected $signature = 'debug:race-results {slug} {year}';
    protected $description = 'Debug helper: toon counts van race_results per result_type.';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $year = (int) $this->argument('year');

        $race = Race::where('pcs_slug', $slug)->where('year', $year)->first();
        if (!$race) {
            $this->error("Race niet gevonden: {$slug} {$year}");
            return self::FAILURE;
        }

        $this->info("Race: {$race->name} ({$race->pcs_slug} {$race->year}) id={$race->id}");

        foreach (['result', 'stage', 'gc', 'points', 'kom', 'youth'] as $type) {
            $count = $race->results()->where('result_type', $type)->whereNotNull('position')->where('status', 'finished')->count();
            $this->line("{$type}: {$count}");
        }

        $this->line('synced_at: ' . ($race->synced_at?->toDateTimeString() ?? 'null'));

        return self::SUCCESS;
    }
}

