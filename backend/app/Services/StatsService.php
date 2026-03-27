<?php

namespace App\Services;

use App\Models\Rider;
use App\Models\Race;
use Illuminate\Support\Collection;

/**
 * Berekent statistieken voor renners met tijdsgewogen gemiddelden.
 *
 * Recentere resultaten wegen zwaarder via exponentiële verval:
 *   gewicht = DECAY ^ jaren_geleden
 *
 *   2026 → 1.0
 *   2025 → 0.70
 *   2024 → 0.49
 *   2023 → 0.34
 *   2022 → 0.24
 *   2021 → 0.17
 *   2020 → 0.12
 *   2019 → 0.08
 */
class StatsService
{
    const DECAY       = 0.7;  // gewichtsfactor per jaar
    const MIN_YEAR    = 2019; // oudste data die meegeteld wordt
    const RESULT_TYPES = ['result', 'gc'];

    // ── Renner statistieken ───────────────────────────────────────────────────

    /**
     * Gewogen gemiddelde positie van een renner.
     * Optioneel gefilterd op parcours type.
     */
    public function weightedAvgPosition(Rider $rider, ?string $parcoursType = null): ?float
    {
        $results = $this->getRiderResults($rider, $parcoursType);
        return $this->weightedAverage($results, 'position');
    }

    /**
     * Gewogen top-10 percentage (0–100).
     */
    public function weightedTop10Rate(Rider $rider, ?string $parcoursType = null): ?float
    {
        $results = $this->getRiderResults($rider, $parcoursType);
        if ($results->isEmpty()) return null;

        $weightedTop10  = 0.0;
        $totalWeight    = 0.0;

        foreach ($results as $row) {
            $weight = $this->weight($row->race_year);
            $totalWeight   += $weight;
            $weightedTop10 += ($row->position <= 10 ? 1 : 0) * $weight;
        }

        return $totalWeight > 0 ? round($weightedTop10 / $totalWeight * 100, 1) : null;
    }

    /**
     * Formtrend: verschil tussen gewogen gem. positie afgelopen 5 races vs. alles.
     * Negatief = verbeterend, positief = achteruitgang.
     */
    public function formTrend(Rider $rider): ?float
    {
        $all    = $this->getRiderResults($rider);
        $recent = $all->sortByDesc('race_date')->take(5);

        $avgAll    = $this->weightedAverage($all, 'position');
        $avgRecent = $this->weightedAverage($recent, 'position');

        if ($avgAll === null || $avgRecent === null) return null;

        return round($avgRecent - $avgAll, 1);
    }

    /**
     * Aantal overwinningen (gewogen).
     */
    public function weightedWins(Rider $rider): float
    {
        $results = $this->getRiderResults($rider);
        $wins = $results->filter(fn($r) => $r->position === 1);

        return round($wins->sum(fn($r) => $this->weight($r->race_year)), 2);
    }

    /**
     * Samenvatting van alle stats voor één renner.
     * Handig voor ML feature vector en weergave.
     */
    public function riderStats(Rider $rider): array
    {
        $currentYear = (int) date('Y');

        return [
            'avg_position'         => $this->weightedAvgPosition($rider),
            'avg_position_flat'    => $this->weightedAvgPosition($rider, 'flat'),
            'avg_position_hilly'   => $this->weightedAvgPosition($rider, 'hilly'),
            'avg_position_mountain'=> $this->weightedAvgPosition($rider, 'mountain'),
            'avg_position_classic' => $this->weightedAvgPosition($rider, 'classic'),
            'top10_rate'           => $this->weightedTop10Rate($rider),
            'form_trend'           => $this->formTrend($rider),
            'weighted_wins'        => $this->weightedWins($rider),
            'total_results'        => $this->getRiderResults($rider)->count(),
        ];
    }

    // ── Race statistieken ─────────────────────────────────────────────────────

    /**
     * Top N finishers van een race, inclusief hun gewogen stats.
     * Nuttig voor de detailpagina van een race.
     */
    public function topFinishers(Race $race, int $limit = 10): Collection
    {
        $resultType = $race->isOneDay() ? 'result' : 'gc';

        return $race->results()
            ->where('result_type', $resultType)
            ->whereNotNull('position')
            ->where('status', 'finished')
            ->orderBy('position')
            ->limit($limit)
            ->with('rider.team')
            ->get()
            ->map(fn($result) => [
                'position'   => $result->position,
                'rider_name' => $result->rider->full_name,
                'rider_slug' => $result->rider->pcs_slug,
                'team'       => $result->rider->team?->name ?? '–',
                'gap'        => $result->gap_seconds
                    ? '+' . gmdate('H:i:s', $result->gap_seconds)
                    : 'winnaar',
            ]);
    }

    // ── Interne helpers ───────────────────────────────────────────────────────

    /**
     * Haal gefilterde resultaten op voor een renner als platte collectie
     * met het racejaar en datum erbij (nodig voor gewichtsberekening).
     */
    private function getRiderResults(Rider $rider, ?string $parcoursType = null): Collection
    {
        $query = $rider->raceResults()
            ->whereIn('result_type', self::RESULT_TYPES)
            ->whereNotNull('position')
            ->where('status', 'finished')
            ->join('races', 'race_results.race_id', '=', 'races.id')
            ->where('races.year', '>=', self::MIN_YEAR)
            ->select(
                'race_results.*',
                'races.year as race_year',
                'races.start_date as race_date',
                'races.parcours_type as parcours_type',
            );

        if ($parcoursType) {
            $query->where('races.parcours_type', $parcoursType);
        }

        return $query->get();
    }

    /**
     * Berekent het tijdsgewogen gemiddelde van een kolom.
     */
    private function weightedAverage(Collection $results, string $column): ?float
    {
        if ($results->isEmpty()) return null;

        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($results as $row) {
            $value = is_object($row) ? $row->{$column} : $row[$column];
            if ($value === null) continue;

            $year   = is_object($row) ? ($row->race_year ?? (int) date('Y')) : ($row['race_year'] ?? (int) date('Y'));
            $weight = $this->weight($year);

            $weightedSum += $value * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 1) : null;
    }

    /**
     * Tijdsgewicht voor een gegeven jaar.
     * Huidig jaar = 1.0, elk jaar eerder × DECAY.
     */
    private function weight(int $year): float
    {
        $yearsAgo = max(0, (int) date('Y') - $year);
        return pow(self::DECAY, $yearsAgo);
    }
}
