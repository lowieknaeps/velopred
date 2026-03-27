<?php

namespace App\Services;

use App\Helpers\NameHelper;
use App\Models\Rider;
use App\Models\Team;
use Illuminate\Support\Facades\Log;

class TeamSyncService
{
    public function __construct(
        private ExternalCyclingApiService $api
    ) {}

    /**
     * Synchroniseert alle WorldTeam + ProTeam ploegen en hun renners voor een jaar.
     *
     * @return array{teams: int, riders: int}
     */
    public function syncAllTeams(int $year): array
    {
        Log::info("[TeamSync] Start sync alle ploegen voor {$year}");

        $data       = $this->api->getTeams($year);
        $teamList   = $data['teams'] ?? [];
        $totalRiders = 0;

        foreach ($teamList as $teamEntry) {
            $riders = $this->syncTeam($teamEntry['pcs_slug'], $teamEntry['name'], $teamEntry['category'], $year);
            $totalRiders += $riders;
        }

        Log::info("[TeamSync] Klaar: " . count($teamList) . " ploegen, {$totalRiders} renners gesynchroniseerd");

        return [
            'teams'  => count($teamList),
            'riders' => $totalRiders,
        ];
    }

    /**
     * Synchroniseert één team en zijn renners.
     * Geeft het aantal aangemakte/bijgewerkte renners terug.
     */
    public function syncTeam(string $slug, string $name, string $category, int $year): int
    {
        Log::info("[TeamSync] Syncing team: {$name}");

        // Verwijder het jaarsuffix voor de pcs_slug opslag
        // bv. "uae-team-emirates-xrg-2026" → "uae-team-emirates-xrg-2026" (bewaar volledig)
        $team = Team::updateOrCreate(
            ['pcs_slug' => $slug],
            [
                'name'     => $name,
                'category' => $category,
            ]
        );

        try {
            $data    = $this->api->getTeamRoster($slug);
            $riders  = $data['riders'] ?? [];
            $count   = 0;

            foreach ($riders as $entry) {
                [$firstName, $lastName] = NameHelper::parse($entry['rider_name'] ?? '');

                $rider = Rider::updateOrCreate(
                    ['pcs_slug' => $entry['rider_slug']],
                    [
                        'first_name'    => $firstName,
                        'last_name'     => $lastName,
                        'nationality'   => $entry['nationality'] ?? null,
                        'team_id'       => $team->id,
                        'career_points' => $entry['career_points'] ?? null,
                        'pcs_ranking'   => $entry['pcs_ranking'] ?? null,
                        'age_approx'    => $entry['age'] ?? null,
                    ]
                );

                // Schat geboortedatum op basis van leeftijd (alleen als nog niet bekend)
                if (empty($rider->date_of_birth) && !empty($entry['age'])) {
                    $rider->date_of_birth = (date('Y') - $entry['age']) . '-07-01';
                    $rider->save();
                }
                $count++;
            }

            return $count;
        } catch (\RuntimeException $e) {
            Log::warning("[TeamSync] Team niet gevonden op PCS: {$slug} — {$e->getMessage()}");
            return 0;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

}
