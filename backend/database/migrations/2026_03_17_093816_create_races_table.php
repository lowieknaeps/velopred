<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('races', function (Blueprint $table) {
            $table->id();
            $table->string('pcs_slug');                  // bv. "tour-de-france"
            $table->string('name');                      // bv. "Tour de France"
            $table->unsignedSmallInteger('year');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('country', 3)->nullable();    // ISO 3166-1 alpha-3
            $table->string('category')->nullable();      // 1.UWT, 2.UWT, 1.Pro, ...

            // Belangrijk voor ML: type bepaalt welke renners favoriet zijn
            $table->enum('race_type', ['one_day', 'stage_race'])->default('one_day');

            // Cruciaal voor ML: een klimmer presteert anders op vlak parcours
            $table->enum('parcours_type', ['flat', 'hilly', 'mountain', 'tt', 'classic', 'mixed'])
                  ->default('mixed');

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['pcs_slug', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('races');
    }
};
