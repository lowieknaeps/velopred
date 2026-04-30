<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // SQLite already handles this via the table-rewrite migration that updates the CHECK constraint.
        if ($driver !== 'mysql') {
            return;
        }

        // MySQL ENUM needs to explicitly include the new value.
        DB::statement(
            "ALTER TABLE races MODIFY parcours_type ENUM('flat','hilly','mountain','tt','classic','cobbled','mixed') NOT NULL DEFAULT 'mixed'"
        );
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        // Downgrade: remove 'cobbled' from the enum.
        // Any existing 'cobbled' values are mapped back to 'classic'.
        DB::statement("UPDATE races SET parcours_type='classic' WHERE parcours_type='cobbled'");
        DB::statement(
            "ALTER TABLE races MODIFY parcours_type ENUM('flat','hilly','mountain','tt','classic','mixed') NOT NULL DEFAULT 'mixed'"
        );
    }
};

