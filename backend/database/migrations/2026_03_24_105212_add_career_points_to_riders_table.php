<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->unsignedInteger('career_points')->nullable()->after('pcs_ranking');
            $table->unsignedTinyInteger('age_approx')->nullable()->after('career_points');
        });
    }

    public function down(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->dropColumn(['career_points', 'age_approx']);
        });
    }
};
