<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('bib_number')->nullable();
            $table->timestamps();

            $table->unique(['race_id', 'rider_id']);
            $table->index('race_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_entries');
    }
};
