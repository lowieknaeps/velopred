<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->integer('pcs_speciality_one_day')->nullable()->after('career_points');
            $table->integer('pcs_speciality_gc')->nullable()->after('pcs_speciality_one_day');
            $table->integer('pcs_speciality_tt')->nullable()->after('pcs_speciality_gc');
            $table->integer('pcs_speciality_sprint')->nullable()->after('pcs_speciality_tt');
            $table->integer('pcs_speciality_climber')->nullable()->after('pcs_speciality_sprint');
            $table->integer('pcs_speciality_hills')->nullable()->after('pcs_speciality_climber');
            $table->decimal('pcs_weight_kg', 5, 2)->nullable()->after('pcs_speciality_hills');
            $table->decimal('pcs_height_m', 4, 2)->nullable()->after('pcs_weight_kg');
            $table->timestamp('profile_synced_at')->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->dropColumn([
                'pcs_speciality_one_day',
                'pcs_speciality_gc',
                'pcs_speciality_tt',
                'pcs_speciality_sprint',
                'pcs_speciality_climber',
                'pcs_speciality_hills',
                'pcs_weight_kg',
                'pcs_height_m',
                'profile_synced_at',
            ]);
        });
    }
};
