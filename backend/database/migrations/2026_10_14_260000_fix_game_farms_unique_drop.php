<?php

use App\Support\WorkspaceSavegamesSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Retries savegame schema after MariaDB refused dropping a unique used by workspace_id FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        WorkspaceSavegamesSchema::ensure();
    }

    public function down(): void
    {
        //
    }
};
