<?php

namespace App\Services;

use App\Models\Race;
use Illuminate\Support\Facades\Log;

class CalendarSyncService
{
    /**
     * Curated parcours type per PCS slug.
     * Overschrijft de automatisch bepaalde waarde uit de kalender-scrape.
     * Gebaseerd op het vaste karakter van de koers (niet seizoensgebonden).
     */
    const PARCOURS_MAP = [
        // ── Kasseienklassiekers ───────────────────────────────────────────────
        'paris-roubaix'             => 'cobbled',
        'ronde-van-vlaanderen'      => 'cobbled',
        'e3-harelbeke'              => 'cobbled',
        'gent-wevelgem'             => 'cobbled',
        'dwars-door-vlaanderen'     => 'cobbled',
        'omloop-het-nieuwsblad'     => 'cobbled',
        'kuurne-brussel-kuurne'     => 'cobbled',
        'strade-bianche'            => 'cobbled',   // gravel + kasseien

        // ── Sprinterklassiekers ───────────────────────────────────────────────
        'classic-brugge-de-panne'   => 'flat',      // Brugge-De Panne
        'scheldeprijs'              => 'flat',
        'cyclassics-hamburg'        => 'flat',
        'copenhagen-sprint'         => 'flat',
        'renewi-tour'               => 'flat',

        // ── Heuvels / punchers ────────────────────────────────────────────────
        'amstel-gold-race'          => 'hilly',
        'la-fleche-wallonne'        => 'hilly',
        'eschborn-frankfurt'        => 'hilly',
        'gp-quebec'                 => 'hilly',
        'gp-montreal'               => 'hilly',
        'bretagne-classic'          => 'hilly',
        'san-sebastian'             => 'hilly',
        'in-flanders-fields'        => 'cobbled',

        // ── Grote klassiekers (all-round) ─────────────────────────────────────
        'milano-sanremo'            => 'classic',
        'liege-bastogne-liege'      => 'classic',
        'il-lombardia'              => 'classic',
        'world-championship'        => 'classic',   // WK wegrit

        // ── Tijdritten ────────────────────────────────────────────────────────
        'world-championship-itt'    => 'tt',

        // ── Grote ronden (bergklimkoersen) ────────────────────────────────────
        'tour-de-france'            => 'mountain',
        'giro-d-italia'             => 'mountain',
        'vuelta-a-espana'           => 'mountain',

        // ── Etappekoersen ─────────────────────────────────────────────────────
        'itzulia-basque-country'    => 'mountain',
        'volta-a-catalunya'         => 'mountain',
        'tour-de-romandie'          => 'mountain',
        'tour-de-suisse'            => 'mountain',
        'dauphine'                  => 'mountain',
        'tour-de-pologne'           => 'hilly',
        'tour-of-guangxi'           => 'mountain',
    ];

    public function __construct(
        private ExternalCyclingApiService $api
    ) {}

    /**
     * Synchroniseert de volledige WorldTour + ProSeries kalender voor een jaar.
     * Maakt nieuwe race-records aan, updatet bestaande.
     * Haalt geen resultaten op — dat doet AutoSyncFinishedRacesJob.
     *
     * @return array{new: int, updated: int, total: int}
     */
    public function syncCalendar(int $year): array
    {
        Log::info("[CalendarSync] Start kalender sync voor {$year}");

        $data  = $this->api->getCalendar($year);
        $races = $data['races'] ?? [];

        $new     = 0;
        $updated = 0;

        foreach ($races as $entry) {
            // Vrouwen-, junior- en beloftenwedstrijden overslaan
            if ($this->isIrrelevant($entry['name'])) {
                continue;
            }

            $isOneDay  = $entry['start_date'] === $entry['end_date'];
            $raceType  = $isOneDay ? 'one_day' : 'stage_race';

            $exists = Race::where('pcs_slug', $entry['pcs_slug'])
                ->where('year', $entry['year'])
                ->exists();

            $parcoursType = self::PARCOURS_MAP[$entry['pcs_slug']] ?? 'mixed';

            Race::updateOrCreate(
                [
                    'pcs_slug' => $entry['pcs_slug'],
                    'year'     => $entry['year'],
                ],
                [
                    'name'          => $entry['name'],
                    'start_date'    => $entry['start_date'],
                    'end_date'      => $entry['end_date'],
                    'category'      => $entry['category'],
                    'race_type'     => $raceType,
                    'parcours_type' => $parcoursType,
                ]
            );

            $exists ? $updated++ : $new++;
        }

        Log::info("[CalendarSync] Klaar: {$new} nieuw, {$updated} bijgewerkt");

        return [
            'new'     => $new,
            'updated' => $updated,
            'total'   => count($races),
        ];
    }

    /**
     * Bepaalt of een race niet relevant is voor dit platform
     * (vrouwen, junioren, beloften, mixed relay).
     */
    private function isIrrelevant(string $name): bool
    {
        $patterns = [' WE - ', ' WJ - ', ' WU - ', ' MJ - ', ' MU - ', 'Women', 'Mixed Relay'];
        foreach ($patterns as $pattern) {
            if (str_contains($name, $pattern)) return true;
        }
        return false;
    }
}
