<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->decimal('raw_top10_probability', 8, 4)->nullable()->after('top10_probability');
            $table->decimal('raw_win_probability', 8, 4)->nullable()->after('win_probability');
        });

        DB::statement(
            'UPDATE predictions
             SET raw_top10_probability = top10_probability,
                 raw_win_probability = win_probability
             WHERE raw_top10_probability IS NULL
                OR raw_win_probability IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->dropColumn(['raw_top10_probability', 'raw_win_probability']);
        });
    }
};
