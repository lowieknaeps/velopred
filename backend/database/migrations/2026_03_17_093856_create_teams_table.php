<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('pcs_slug')->unique();        // bv. "soudal-quick-step"
            $table->string('name');                      // bv. "Soudal Quick-Step"
            $table->string('nationality', 3)->nullable(); // ISO 3166-1 alpha-3
            $table->string('category')->nullable();      // WorldTeam, ProTeam, Continental
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
