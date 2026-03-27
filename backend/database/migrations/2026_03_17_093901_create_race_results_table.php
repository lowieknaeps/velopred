<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            // Waarde van result_type bepaalt context:
            //   "result"  → uitslag eendagskoers
            //   "stage"   → etappe-uitslag (stage_number geeft de etappe)
            //   "gc"      → eindklassement
            //   "points"  → puntenklassement
            //   "mountains" → bergklassement
            //   "youth"   → jongerenklassement
            $table->string('result_type')->default('result');
            $table->unsignedTinyInteger('stage_number')->nullable(); // enkel bij stage races

            // Positie: null = niet gefinisht
            $table->unsignedSmallInteger('position')->nullable();
            $table->enum('status', ['finished', 'dnf', 'dns', 'dnq', 'dsq'])->default('finished');

            $table->unsignedInteger('time_seconds')->nullable();   // rijtijd in seconden
            $table->unsignedInteger('gap_seconds')->nullable();    // verschil met leider
            $table->unsignedSmallInteger('pcs_points')->nullable();
            $table->decimal('uci_points', 8, 2)->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['rider_id', 'result_type']); // voor snelle form-berekeningen
            $table->index(['race_id', 'result_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_results');
    }
};
