<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Models\RaceResult;
use App\Models\Rider;
use Illuminate\Console\Command;

class ImportPcsScraperStagesCommand extends Command
{
    protected $signature = 'import:pcs-stages
        {season : Season year (bv. 2026)}
        {--file=C:\\Users\\lowie\\pcs-scraper\\output\\races.json : Pad naar races.json}
        {--only= : Optionele pcs_slug filter (bv. giro-d-italia)}';

    protected $description = 'Importeer etappe- en eendagsuitslagen uit pcs-scraper races.json naar race_results.';

    public function handle(): int
    {
        $season = (int) $this->argument('season');
        $file = (string) $this->option('file');
        $only = $this->option('only') ? (string) $this->option('only') : null;

        if (!is_file($file)) {
            $this->error("Bestand niet gevonden: {$file}");
            return self::FAILURE;
        }

        $json = file_get_contents($file);
        if ($json === false) {
            $this->error("Kan bestand niet lezen: {$file}");
            return self::FAILURE;
        }

        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            $this->error('JSON parse failed.');
            return self::FAILURE;
        }

        $processedRaces = 0;
        $importedRows = 0;

        foreach ($rows as $row) {
            $raceId = (string) ($row['id'] ?? '');
            if ($raceId === '' || str_contains($raceId, '-stage-')) {
                continue;
            }

            if (!str_ends_with($raceId, '-' . $season)) {
                continue;
            }

            $pcsSlug = preg_replace('/-' . preg_quote((string) $season, '/') . '$/', '', $raceId);
            if (!is_string($pcsSlug) || $pcsSlug === '') {
                continue;
            }

            if ($only !== null && $pcsSlug !== $only) {
                continue;
            }

            $race = Race::query()->where('pcs_slug', $pcsSlug)->where('year', $season)->first();
            if (!$race) {
                $this->warn("Race niet gevonden in DB: {$pcsSlug} {$season}");
                continue;
            }

            $stages = $row['stages'] ?? [];
            if (!is_array($stages) || count($stages) === 0) {
                continue;
            }

            $processedRaces++;

            foreach ($stages as $stage) {
                $stageId = (string) ($stage['id'] ?? '');
                if (!preg_match('/-stage-(\d+)$/', $stageId, $matches)) {
                    continue;
                }

                $stageNumber = (int) $matches[1];
                $timeRows = $stage['stageResults']['time'] ?? [];
                if (!is_array($timeRows)) {
                    continue;
                }

                if ($race->isOneDay()) {
                    RaceResult::query()
                        ->where('race_id', $race->id)
                        ->where('result_type', 'result')
                        ->whereNull('stage_number')
                        ->delete();
                } else {
                    RaceResult::query()
                        ->where('race_id', $race->id)
                        ->where('result_type', 'stage')
                        ->where('stage_number', $stageNumber)
                        ->delete();
                }

                foreach ($timeRows as $item) {
                    $riderSlug = (string) ($item['participant'] ?? '');
                    $position = isset($item['position']) ? (int) $item['position'] : null;

                    if ($riderSlug === '' || $position === null || $position <= 0) {
                        continue;
                    }

                    $rider = Rider::query()->where('pcs_slug', $riderSlug)->first();
                    if (!$rider) {
                        continue;
                    }

                    $resultType = $race->isOneDay() ? 'result' : 'stage';
                    $storedStageNumber = $race->isOneDay() ? null : $stageNumber;

                    RaceResult::query()->updateOrCreate(
                        [
                            'race_id' => $race->id,
                            'rider_id' => $rider->id,
                            'result_type' => $resultType,
                            'stage_number' => $storedStageNumber,
                        ],
                        [
                            'team_id' => null,
                            'position' => $position,
                            'status' => 'finished',
                            'time_seconds' => isset($item['time']) ? (int) $item['time'] : null,
                            'gap_seconds' => null,
                            'pcs_points' => null,
                            'uci_points' => null,
                            'synced_at' => now(),
                        ]
                    );

                    $importedRows++;
                }
            }
        }

        $this->info("Import klaar. races={$processedRaces}, stage_rows={$importedRows}");
        return self::SUCCESS;
    }
}
