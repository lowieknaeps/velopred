<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rider_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('predicted_position');
            $table->decimal('top10_probability', 5, 4);  // 0.0000 – 1.0000
            $table->decimal('win_probability', 5, 4);
            $table->decimal('confidence_score', 5, 4);

            // Welke features werden gebruikt? Handig voor uitleg + debugging
            $table->json('features');

            // Versie van het model waarmee voorspeld werd
            $table->string('model_version')->default('v1');

            $table->timestamps();

            $table->unique(['race_id', 'rider_id', 'model_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
