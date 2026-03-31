<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('startlist_order')->nullable()->after('bib_number');
            $table->index(['race_id', 'startlist_order']);
        });
    }

    public function down(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            $table->dropIndex(['race_id', 'startlist_order']);
            $table->dropColumn('startlist_order');
        });
    }
};

