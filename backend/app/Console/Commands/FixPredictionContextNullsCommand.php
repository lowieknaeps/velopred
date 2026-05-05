<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixPredictionContextNullsCommand extends Command
{
    protected $signature = 'fix:prediction-context-nulls
                            {--dry-run : Only show what would change}';

    protected $description = 'Backfill NULL prediction_type/stage_number and normalize legacy result_type values.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('🔎 Checking predictions for NULL context columns...');
        $predNullType = (int) DB::table('predictions')->whereNull('prediction_type')->count();
        $predNullStage = (int) DB::table('predictions')->whereNull('stage_number')->count();

        $this->line("predictions.prediction_type NULL: {$predNullType}");
        $this->line("predictions.stage_number NULL: {$predNullStage}");

        $this->info('🔎 Checking race_results legacy result_type values...');
        $mountains = (int) DB::table('race_results')->where('result_type', 'mountains')->count();
        $this->line("race_results.result_type = mountains: {$mountains}");

        if ($dryRun) {
            $this->comment('Dry-run: no changes applied.');
            return self::SUCCESS;
        }

        $updated = 0;
        if ($predNullType > 0) {
            $updated += DB::table('predictions')
                ->whereNull('prediction_type')
                ->update(['prediction_type' => 'result']);
        }
        if ($predNullStage > 0) {
            $updated += DB::table('predictions')
                ->whereNull('stage_number')
                ->update(['stage_number' => 0]);
        }

        if ($mountains > 0) {
            DB::table('race_results')
                ->where('result_type', 'mountains')
                ->update(['result_type' => 'kom']);
        }

        $this->info("✅ Done. Updated rows: {$updated} (predictions); normalized mountains→kom: {$mountains}.");
        return self::SUCCESS;
    }
}

