<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Voegt 'cobbled' toe aan de parcours_type waarden en
 * past kasseienklassiekers aan.
 *
 * SQLite ondersteunt geen ALTER COLUMN, dus we passen de
 * CHECK constraint direct aan via writable_schema.
 */
return new class extends Migration
{
    const COBBLED_SLUGS = [
        'ronde-van-vlaanderen',
        'paris-roubaix',
        'paris-roubaix-hauts-de-france',
        'omloop-het-nieuwsblad',
        'e3-saxo-classic-me',
        'dwars-door-vlaanderen-a-travers-la-flandre-me',
        'gent-wevelgem-in-flanders-fields-me',
        'ronde-van-brugge-tour-of-bruges-me',
    ];

    public function up(): void
    {
        // Stap 1: voeg de nieuwe waarde toe aan parcours_type.
        //
        // SQLite: de column heeft een CHECK constraint en SQLite ondersteunt geen ALTER COLUMN,
        // dus we recreeren de tabel (writable_schema aanpak).
        //
        // MySQL/Postgres: het veld is gewoon VARCHAR, geen constraint; daar is niets te doen.
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');

            DB::statement('
                CREATE TABLE races_new AS SELECT * FROM races
            ');

            DB::statement('DROP TABLE races');

            DB::statement('
                CREATE TABLE races (
                    id              INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    pcs_slug        VARCHAR NOT NULL,
                    name            VARCHAR NOT NULL,
                    year            INTEGER NOT NULL,
                    start_date      DATE NOT NULL,
                    end_date        DATE,
                    country         VARCHAR,
                    category        VARCHAR,
                    race_type       VARCHAR CHECK(race_type IN (\'one_day\',\'stage_race\')) DEFAULT \'one_day\' NOT NULL,
                    parcours_type   VARCHAR CHECK(parcours_type IN (\'flat\',\'hilly\',\'mountain\',\'tt\',\'classic\',\'cobbled\',\'mixed\')) DEFAULT \'mixed\' NOT NULL,
                    synced_at       DATETIME,
                    created_at      DATETIME,
                    updated_at      DATETIME,
                    UNIQUE(pcs_slug, year)
                )
            ');

            DB::statement('INSERT INTO races SELECT * FROM races_new');
            DB::statement('DROP TABLE races_new');
            DB::statement('PRAGMA foreign_keys = ON');
        }

        // Stap 2: Kasseienklassiekers bijwerken
        $updated = DB::table('races')
            ->whereIn('pcs_slug', self::COBBLED_SLUGS)
            ->update(['parcours_type' => 'cobbled']);

        echo "  → {$updated} kasseienklassiekers bijgewerkt naar 'cobbled'\n";
    }

    public function down(): void
    {
        DB::table('races')
            ->whereIn('pcs_slug', self::COBBLED_SLUGS)
            ->update(['parcours_type' => 'classic']);
    }
};
