<?php

namespace App\Console\Commands;

use App\Models\Race;
use Illuminate\Console\Command;

class DebugStageResultsCommand extends Command
{
    protected $signature = 'debug:stage-results {slug} {year}';
    protected $description = 'Debug helper: toon welke stage_numbers resultaten hebben (en top 3 namen) voor een rittenkoers.';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $year = (int) $this->argument('year');

        $race = Race::where('pcs_slug', $slug)->where('year', $year)->first();
        if (!$race) {
            $this->error("Race niet gevonden: {$slug} {$year}");
            return self::FAILURE;
        }

        $stageNumbers = $race->results()
            ->where('result_type', 'stage')
            ->whereNotNull('position')
            ->where('status', 'finished')
            ->select('stage_number')
            ->distinct()
            ->orderBy('stage_number')
            ->pluck('stage_number')
            ->map(fn ($v) => (int) $v)
            ->values();

        if ($stageNumbers->isEmpty()) {
            $this->warn('Geen stage resultaten gevonden.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($stageNumbers as $nr) {
            $top = $race->results()
                ->where('result_type', 'stage')
                ->where('stage_number', $nr)
                ->whereNotNull('position')
                ->where('status', 'finished')
                ->orderBy('position')
                ->with('rider')
                ->limit(3)
                ->get();

            $rows[] = [
                $nr,
                $top->get(0)?->rider?->pcs_slug ?? '–',
                $top->get(1)?->rider?->pcs_slug ?? '–',
                $top->get(2)?->rider?->pcs_slug ?? '–',
            ];
        }

        $this->info("Race: {$race->name} ({$race->pcs_slug} {$race->year})");
        $this->table(['stage_number', '#1', '#2', '#3'], $rows);
        return self::SUCCESS;
    }
}

