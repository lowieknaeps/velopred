<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rider_id')->constrained()->cascadeOnDelete();

            $table->date('date')->nullable();
            $table->unsignedSmallInteger('position')->nullable();
            $table->enum('status', ['finished', 'dnf', 'dns', 'dnq', 'dsq'])->default('finished');

            // Raw PCS metadata (stage results, GC entries, etc.)
            $table->string('race_name')->nullable();
            $table->string('race_slug')->nullable();
            $table->string('race_url');
            $table->string('race_class')->nullable();

            $table->unsignedSmallInteger('pcs_points')->nullable();
            $table->decimal('uci_points', 8, 2)->nullable();
            $table->unsignedSmallInteger('season')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['rider_id', 'date']);
            $table->index(['rider_id', 'status', 'position']);
            $table->index(['race_slug', 'date']);

            // MySQL name length safe.
            $table->unique(['rider_id', 'race_url', 'date'], 'riderres_rider_url_date_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_results');
    }
};

