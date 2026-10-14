<?php

use App\Support\WorkspaceSavegamesSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-applies idempotent savegame schema for installs that partially failed on 240000.
 */
return new class extends Migration
{
    public function up(): void
    {
        WorkspaceSavegamesSchema::ensure();
    }

    public function down(): void
    {
        // repair only
    }
};
