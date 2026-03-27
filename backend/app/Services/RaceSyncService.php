<?php

namespace App\Services;

use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceResult;
use App\Models\Rider;
use App\Models\Team;
use Illuminate\Support\Facades\Log;

class RaceSyncService
{
    public function __construct(
        private ExternalCyclingApiService $api,
        private RiderSyncService $riderSync,
    ) {}

    /**
     * Hoofdmethode: synchroniseert een volledige wedstrijd.
     *
     * Stap 1: Race metadata ophalen en opslaan
     * Stap 2: Startlijst ophalen → renners + teams aanmaken
     * Stap 3: Resultaten ophalen en opslaan
     *
     * @param bool $withStartlist  False bij historische syncs: renners bestaan al via sync:teams
     * @return Race
     */
    public function syncRace(string $slug, int $year, bool $withStartlist = true): Race
    {
        Log::info("[RaceSync] Start sync: {$slug} {$year}");

        // Stap 1: Race metadata
        $race = $this->syncRaceMeta($slug, $year);

        // Stap 2: Startlijst (optioneel — weglaten voor snellere historische sync)
        if ($withStartlist) {
            $this->syncStartlist($race);
        }

        // Stap 3: Resultaten
        $this->syncResults($race);

        Log::info("[RaceSync] Klaar: {$race->name} {$year}");

        return $race;
    }

    public function refreshRaceMeta(Race $race): Race
    {
        return $this->syncRaceMeta($race->pcs_slug, $race->year);
    }

    // ── Stap 1: Metadata ──────────────────────────────────────────────────────

    private function syncRaceMeta(string $slug, int $year): Race
    {
        $data = $this->api->getRace($slug, $year);

        $race = Race::updateOrCreate(
            ['pcs_slug' => $slug, 'year' => $year],
            [
                'name'          => $data['name'],
                'start_date'    => $data['start_date'],
                'end_date'      => $data['end_date'],
                'country'       => $data['country'],
                'category'      => $data['category'],
                'race_type'     => $data['race_type'],
                'parcours_type' => $data['parcours_type'],
                'stages_json'   => $data['stages'] ?? null,
                'synced_at'     => now(),
            ]
        );

        Log::info("[RaceSync] Meta opgeslagen: {$race->name}");

        return $race;
    }

    // ── Stap 2: Startlijst ────────────────────────────────────────────────────

    /**
     * Synchroniseert enkel de startlijst van een race.
     * Nuttig voor komende races zodat de website de deelnemers al kan tonen.
     */
    public function syncStartlistOnly(Race $race): void
    {
        $race = $this->refreshRaceMeta($race);
        $this->syncStartlist($race);
    }

    private function syncStartlist(Race $race): void
    {
        try {
            $data = $this->api->getStartlist($race->pcs_slug, $race->year);
        } catch (\RuntimeException $e) {
            Log::warning("[RaceSync] Geen startlijst voor {$race->name}: {$e->getMessage()}");
            return;
        }

        $currentRiderIds = [];

        foreach ($data['riders'] as $entry) {
            $rider = $this->riderSync->syncFromStartlistEntry($entry);
            $team  = Team::where('pcs_slug', $entry['team_slug'])->first();
            $currentRiderIds[] = $rider->id;

            // Sla op in race_entries zodat we weten wie er start
            RaceEntry::updateOrCreate(
                ['race_id' => $race->id, 'rider_id' => $rider->id],
                [
                    'team_id'    => $team?->id,
                    'bib_number' => $entry['rider_number'] ?? null,
                ]
            );
        }

        // PCS startlijsten wijzigen vaak nog kort voor de koers.
        // Verwijder daarom renners die niet langer in de actuele feed zitten.
        RaceEntry::where('race_id', $race->id)
            ->when(!empty($currentRiderIds), fn ($query) => $query->whereNotIn('rider_id', $currentRiderIds))
            ->delete();

        $race->forceFill(['startlist_synced_at' => now()])->save();

        Log::info("[RaceSync] Startlijst gesynchroniseerd: " . count($data['riders']) . " renners");
    }

    // ── Stap 3: Resultaten ────────────────────────────────────────────────────

    private function syncResults(Race $race): void
    {
        if ($race->isOneDay()) {
            $this->syncOneDayResult($race);
        } else {
            $this->syncStageRaceResults($race);
        }
    }

    private function syncOneDayResult(Race $race): void
    {
        try {
            $data = $this->api->getOneDayResult($race->pcs_slug, $race->year);
            $this->replaceResults($race, 'result');
            $this->saveResults($race, $data['results'], 'result');
            Log::info("[RaceSync] Eendagsuitslag opgeslagen: {$race->name}");
        } catch (\RuntimeException $e) {
            Log::warning("[RaceSync] Geen uitslag voor {$race->name}: {$e->getMessage()}");
        }
    }

    private function syncStageRaceResults(Race $race): void
    {
        // Haal de etappelijst op uit de al opgeslagen race meta
        $raceData = $this->api->getRace($race->pcs_slug, $race->year);
        $stages = $raceData['stages'] ?? [];

        foreach ($stages as $stage) {
            $nr = $stage['number'];
            try {
                $data = $this->api->getStageResult($race->pcs_slug, $race->year, $nr);
                $this->replaceResults($race, 'stage', $nr);
                $this->saveResults($race, $data['results'], 'stage', $nr);
                Log::info("[RaceSync] Etappe {$nr} opgeslagen");
            } catch (\RuntimeException $e) {
                Log::warning("[RaceSync] Etappe {$nr} niet beschikbaar: {$e->getMessage()}");
            }
        }

        // GC eindklassement
        try {
            $data = $this->api->getGcResult($race->pcs_slug, $race->year);
            $this->replaceResults($race, 'gc');
            $this->saveResults($race, $data['results'], 'gc');
            Log::info("[RaceSync] GC opgeslagen");
        } catch (\RuntimeException $e) {
            Log::warning("[RaceSync] Geen GC voor {$race->name}: {$e->getMessage()}");
        }

        foreach ([
            'points' => 'getPointsResult',
            'kom'    => 'getKomResult',
            'youth'  => 'getYouthResult',
        ] as $resultType => $method) {
            try {
                $data = $this->api->{$method}($race->pcs_slug, $race->year);
                $this->replaceResults($race, $resultType);
                $this->saveResults($race, $data['results'], $resultType);
                Log::info("[RaceSync] {$resultType} opgeslagen");
            } catch (\RuntimeException $e) {
                Log::warning("[RaceSync] Geen {$resultType} voor {$race->name}: {$e->getMessage()}");
            }
        }
    }

    // ── Resultaten opslaan ────────────────────────────────────────────────────

    private function saveResults(Race $race, array $results, string $resultType, ?int $stageNumber = null): void
    {
        foreach ($results as $result) {
            $rider = Rider::where('pcs_slug', $result['rider_slug'])->first();
            if (!$rider) {
                Log::warning("[RaceSync] Renner niet gevonden: {$result['rider_slug']}");
                continue;
            }

            $team = $result['team_slug']
                ? Team::where('pcs_slug', $result['team_slug'])->first()
                : null;

            RaceResult::updateOrCreate(
                [
                    'race_id'      => $race->id,
                    'rider_id'     => $rider->id,
                    'result_type'  => $resultType,
                    'stage_number' => $stageNumber,
                ],
                [
                    'team_id'      => $team?->id,
                    'position'     => $result['position'],
                    'status'       => $result['status'] ?? 'finished',
                    'time_seconds' => $result['time_seconds'],
                    'gap_seconds'  => $result['gap_seconds'],
                    'pcs_points'   => $result['pcs_points'],
                    'uci_points'   => $result['uci_points'],
                    'synced_at'    => now(),
                ]
            );
        }
    }

    private function replaceResults(Race $race, string $resultType, ?int $stageNumber = null): void
    {
        RaceResult::where('race_id', $race->id)
            ->where('result_type', $resultType)
            ->where('stage_number', $stageNumber)
            ->delete();
    }
}
