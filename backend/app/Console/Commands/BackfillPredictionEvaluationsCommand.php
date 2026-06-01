<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\PredictionEvaluationService;
use Illuminate\Console\Command;

class BackfillPredictionEvaluationsCommand extends Command
{
    protected $signature = 'evaluations:backfill
        {--year= : Alleen races van dit jaar}
        {--race= : Alleen deze race slug}
        {--limit=0 : Max aantal races (0 = geen limiet)}';

    protected $description = 'Backfill prediction evaluations voor alle contexts (result/stage/gc/points/kom/youth) met beschikbare predictions + uitslagen.';

    public function handle(PredictionEvaluationService $evaluationService): int
    {
        $year = $this->option('year') !== null ? (int) $this->option('year') : null;
        $slug = $this->option('race') ? trim((string) $this->option('race')) : null;
        $limit = max(0, (int) $this->option('limit'));

        $query = Race::query()
            ->whereHas('predictions')
            ->whereHas('results', fn ($q) => $q->whereNotNull('position')->where('status', 'finished'))
            ->orderByDesc('year')
            ->orderByDesc('start_date');

        if ($year !== null) {
            $query->where('year', $year);
        }

        if ($slug) {
            $query->where('pcs_slug', $slug);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $races = $query->get();
        if ($races->isEmpty()) {
            $this->warn('Geen races gevonden met predictions + uitslagen voor deze filter.');
            return self::SUCCESS;
        }

        $this->info("Backfill start voor {$races->count()} race(s)...");

        $evaluatedContexts = 0;
        $skippedContexts = 0;

        foreach ($races as $race) {
            $contexts = $race->predictions()
                ->select('prediction_type', 'stage_number')
                ->distinct()
                ->get();

            $raceEvaluated = 0;
            $raceSkipped = 0;

            foreach ($contexts as $ctx) {
                $type = (string) $ctx->prediction_type;
                $stage = (int) ($ctx->stage_number ?? 0);

                $evaluation = $evaluationService->evaluateRace($race, $type, $stage);
                if ($evaluation) {
                    $evaluatedContexts++;
                    $raceEvaluated++;
                } else {
                    $skippedContexts++;
                    $raceSkipped++;
                }
            }

            $this->line(" - {$race->name} {$race->year}: {$raceEvaluated} geëvalueerd, {$raceSkipped} overgeslagen");
        }

        $this->info("Klaar. Contexts geëvalueerd: {$evaluatedContexts}, overgeslagen: {$skippedContexts}");
        return self::SUCCESS;
    }
}

