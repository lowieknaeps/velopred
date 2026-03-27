<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement(<<<'SQL'
            CREATE TABLE predictions_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                race_id INTEGER NOT NULL,
                rider_id INTEGER NOT NULL,
                predicted_position INTEGER NOT NULL,
                top10_probability NUMERIC NOT NULL,
                win_probability NUMERIC NOT NULL,
                confidence_score NUMERIC NOT NULL,
                features TEXT NOT NULL,
                model_version VARCHAR NOT NULL DEFAULT 'v1',
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (race_id) REFERENCES races(id) ON DELETE CASCADE,
                FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE,
                UNIQUE (race_id, rider_id)
            )
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO predictions_new (
                id,
                race_id,
                rider_id,
                predicted_position,
                top10_probability,
                win_probability,
                confidence_score,
                features,
                model_version,
                created_at,
                updated_at
            )
            SELECT
                p.id,
                p.race_id,
                p.rider_id,
                p.predicted_position,
                p.top10_probability,
                p.win_probability,
                p.confidence_score,
                p.features,
                p.model_version,
                p.created_at,
                p.updated_at
            FROM predictions p
            INNER JOIN (
                SELECT MAX(id) AS keep_id
                FROM predictions
                GROUP BY race_id, rider_id
            ) latest ON latest.keep_id = p.id
        SQL);

        Schema::drop('predictions');
        DB::statement('ALTER TABLE predictions_new RENAME TO predictions');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement(<<<'SQL'
            CREATE TABLE predictions_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                race_id INTEGER NOT NULL,
                rider_id INTEGER NOT NULL,
                predicted_position INTEGER NOT NULL,
                top10_probability NUMERIC NOT NULL,
                win_probability NUMERIC NOT NULL,
                confidence_score NUMERIC NOT NULL,
                features TEXT NOT NULL,
                model_version VARCHAR NOT NULL DEFAULT 'v1',
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (race_id) REFERENCES races(id) ON DELETE CASCADE,
                FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE,
                UNIQUE (race_id, rider_id, model_version)
            )
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO predictions_old (
                id,
                race_id,
                rider_id,
                predicted_position,
                top10_probability,
                win_probability,
                confidence_score,
                features,
                model_version,
                created_at,
                updated_at
            )
            SELECT
                id,
                race_id,
                rider_id,
                predicted_position,
                top10_probability,
                win_probability,
                confidence_score,
                features,
                model_version,
                created_at,
                updated_at
            FROM predictions
        SQL);

        Schema::drop('predictions');
        DB::statement('ALTER TABLE predictions_old RENAME TO predictions');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
