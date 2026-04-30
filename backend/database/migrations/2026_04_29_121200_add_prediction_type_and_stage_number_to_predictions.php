<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            if (!Schema::hasColumn('predictions', 'prediction_type')) {
                $table->string('prediction_type')->default('result')->after('rider_id');
            }

            if (!Schema::hasColumn('predictions', 'stage_number')) {
                $table->unsignedInteger('stage_number')->default(0)->after('prediction_type');
            }
        });

        // Replace old unique(race_id, rider_id) with the new key.
        // Name is short to satisfy MySQL identifier length limits.
        $indexes = collect(DB::select("SHOW INDEX FROM predictions"))->pluck('Key_name')->unique()->all();
        if (in_array('predictions_race_id_rider_id_unique', $indexes, true)) {
            Schema::table('predictions', function (Blueprint $table) {
                $table->dropUnique('predictions_race_id_rider_id_unique');
            });
        }

        Schema::table('predictions', function (Blueprint $table) {
            $table->unique(['race_id', 'rider_id', 'prediction_type', 'stage_number'], 'pred_race_rider_type_stage_uq');
        });
    }

    public function down(): void
    {
        // Best-effort rollback.
        $indexes = collect(DB::select("SHOW INDEX FROM predictions"))->pluck('Key_name')->unique()->all();
        if (in_array('pred_race_rider_type_stage_uq', $indexes, true)) {
            Schema::table('predictions', function (Blueprint $table) {
                $table->dropUnique('pred_race_rider_type_stage_uq');
            });
        }

        Schema::table('predictions', function (Blueprint $table) {
            $table->unique(['race_id', 'rider_id'], 'pred_race_rider_uq');

            if (Schema::hasColumn('predictions', 'stage_number')) {
                $table->dropColumn('stage_number');
            }
            if (Schema::hasColumn('predictions', 'prediction_type')) {
                $table->dropColumn('prediction_type');
            }
        });
    }
};
