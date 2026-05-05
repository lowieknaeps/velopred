<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_examples', function (Blueprint $table) {
            $table->id();

            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rider_id')->constrained()->cascadeOnDelete();

            $table->string('prediction_type'); // result|stage|gc|points|kom|youth
            $table->unsignedTinyInteger('stage_number')->default(0);
            $table->string('model_version')->nullable();

            $table->unsignedSmallInteger('predicted_position')->nullable();
            $table->unsignedSmallInteger('actual_position')->nullable();
            $table->enum('actual_status', ['finished', 'dnf', 'dns', 'dnq', 'dsq'])->nullable();

            $table->string('race_slug');
            $table->unsignedSmallInteger('race_year');
            $table->string('race_category')->nullable();
            $table->string('race_type')->nullable(); // one_day|stage_race
            $table->string('parcours_type')->nullable();
            $table->string('stage_subtype')->nullable();

            $table->json('features')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            // Keep names short for MySQL.
            $table->unique(
                ['race_id', 'rider_id', 'prediction_type', 'stage_number', 'model_version'],
                'trainex_race_rider_type_stage_ver_uq'
            );
            $table->index(['race_year', 'prediction_type', 'stage_number'], 'trainex_year_type_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_examples');
    }
};

