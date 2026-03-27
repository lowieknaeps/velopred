<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riders', function (Blueprint $table) {
            $table->id();
            $table->string('pcs_slug')->unique();        // bv. "tadej-pogacar"
            $table->string('first_name');
            $table->string('last_name');
            $table->string('nationality', 3)->nullable(); // ISO 3166-1 alpha-3
            $table->date('date_of_birth')->nullable();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('uci_ranking')->nullable();
            $table->unsignedSmallInteger('pcs_ranking')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riders');
    }
};
