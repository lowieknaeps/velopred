<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->string('prediction_type')->default('result');
            $table->unsignedInteger('stage_number')->default(0);
            $table->boolean('winner_hit')->default(false);
            $table->unsignedInteger('winner_predicted_position')->nullable();
            $table->unsignedInteger('top10_hits')->default(0);
            $table->unsignedInteger('podium_hits')->default(0);
            $table->unsignedInteger('exact_position_hits')->default(0);
            $table->unsignedInteger('shared_top10_riders')->default(0);
            $table->decimal('top10_hit_rate', 6, 4)->nullable();
            $table->decimal('mean_absolute_position_error', 8, 4)->nullable();
            $table->json('metrics');
            $table->timestamp('evaluated_at');
            $table->timestamps();

            // MySQL has a 64 char identifier limit; Laravel's default generated name is too long here.
            $table->unique(['race_id', 'prediction_type', 'stage_number'], 'pred_eval_race_type_stage_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_evaluations');
    }
};
