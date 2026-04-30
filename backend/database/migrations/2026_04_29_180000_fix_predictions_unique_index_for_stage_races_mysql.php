<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Old schema had UNIQUE(race_id, rider_id, model_version) which breaks stage races
        // because the same rider has multiple rows (stage/gc/points/...) per race.
        // Ensure the unique key includes prediction_type + stage_number (+ model_version).
        $indexes = collect(DB::select("SHOW INDEX FROM predictions"))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();

        if (in_array('predictions_race_id_rider_id_model_version_unique', $indexes, true)) {
            Schema::table('predictions', function (Blueprint $table) {
                $table->dropUnique('predictions_race_id_rider_id_model_version_unique');
            });
        }

        // Also drop any legacy unique(race_id, rider_id) if still present.
        if (in_array('predictions_race_id_rider_id_unique', $indexes, true)) {
            Schema::table('predictions', function (Blueprint $table) {
                $table->dropUnique('predictions_race_id_rider_id_unique');
            });
        }

        // Create the new composite key (short name for MySQL 64-char limit).
        $indexes = collect(DB::select("SHOW INDEX FROM predictions"))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();

        if (!in_array('pred_race_rider_type_stage_ver_uq', $indexes, true)) {
            Schema::table('predictions', function (Blueprint $table) {
                $table->unique(
                    ['race_id', 'rider_id', 'prediction_type', 'stage_number', 'model_version'],
                    'pred_race_rider_type_stage_ver_uq'
                );
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $indexes = collect(DB::select("SHOW INDEX FROM predictions"))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();

        if (in_array('pred_race_rider_type_stage_ver_uq', $indexes, true)) {
            Schema::table('predictions', function (Blueprint $table) {
                $table->dropUnique('pred_race_rider_type_stage_ver_uq');
            });
        }
    }
};

