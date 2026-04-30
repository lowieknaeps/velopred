<?php

namespace App\Console\Commands;

use App\Models\Prediction;
use App\Models\Race;
use App\Models\Rider;
use Illuminate\Console\Command;

class DebugPredictionFeaturesCommand extends Command
{
    protected $signature = 'debug:prediction-features {race_slug} {year} {rider_slug}
                            {--type=result : prediction_type (result|stage|gc|points|kom|youth)}
                            {--stage=0 : stage_number (only for stage)}';

    protected $description = 'Debug helper: toon de features die gebruikt zijn voor een specifieke voorspelling.';

    public function handle(): int
    {
        $raceSlug = (string) $this->argument('race_slug');
        $year = (int) $this->argument('year');
        $riderSlug = (string) $this->argument('rider_slug');
        $type = (string) $this->option('type');
        $stage = (int) $this->option('stage');

        $race = Race::where('pcs_slug', $raceSlug)->where('year', $year)->first();
        if (!$race) {
            $this->error("Race niet gevonden: {$raceSlug} {$year}");
            return self::FAILURE;
        }

        $rider = Rider::where('pcs_slug', $riderSlug)->first();
        if (!$rider) {
            $this->error("Renner niet gevonden: {$riderSlug}");
            return self::FAILURE;
        }

        $prediction = Prediction::where('race_id', $race->id)
            ->where('rider_id', $rider->id)
            ->where('prediction_type', $type)
            ->where('stage_number', $stage)
            ->orderByDesc('updated_at')
            ->first();

        if (!$prediction) {
            $this->error("Geen prediction gevonden voor {$raceSlug} {$year} / {$riderSlug} ({$type} stage={$stage})");
            return self::FAILURE;
        }

        $this->info("Race: {$race->name} ({$race->pcs_slug} {$race->year})");
        $this->info("Rider: {$rider->full_name} ({$rider->pcs_slug})");
        $this->line("Context: {$type} stage={$stage} model={$prediction->model_version}");
        $this->line(sprintf("Predicted pos: %s | win=%.2f%% | top10=%.2f%% | conf=%.0f%%",
            (string) $prediction->predicted_position,
            (float) $prediction->win_probability * 100,
            (float) $prediction->top10_probability * 100,
            (float) $prediction->confidence_score * 100,
        ));

        $features = $prediction->features;
        if (!is_array($features)) {
            $features = json_decode((string) $features, true) ?: [];
        }

        $keys = [
            'team',
            'career_points',
            'pcs_ranking',
            'uci_ranking',
            'pcs_speciality_one_day',
            'pcs_speciality_gc',
            'pcs_speciality_climber',
            'pcs_speciality_hills',
            'pcs_speciality_sprint',
            'pcs_speciality_tt',
            'pcs_season_finished_count',
            'pcs_season_top10_rate',
            'pcs_recent_activity_count_30d',
            'recency_weighted_avg_position_10',
            'recent_avg_position',
            'recent_top10_rate',
            'current_year_avg_position',
            'current_year_top10_rate',
            'avg_position',
            'avg_position_parcours',
            'avg_position_stage_subtype',
            'form_trend',
            'race_dynamics_form_adjustment',
            'manual_incident_penalty',
            'pcs_last_incident_days_ago',
            'team_career_points_share',
            'team_startlist_size',
        ];

        $rows = [];
        foreach ($keys as $k) {
            $rows[] = [$k, array_key_exists($k, $features) ? json_encode($features[$k]) : '(missing)'];
        }

        $this->table(['feature', 'value'], $rows);
        return self::SUCCESS;
    }
}

