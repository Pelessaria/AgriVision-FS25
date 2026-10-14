<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('host_accesses')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE host_accesses MODIFY last_error TEXT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE host_accesses ALTER COLUMN last_error TYPE TEXT');
        }
        // sqlite: affinity already flexible
    }

    public function down(): void
    {
        // no-op — widening is safe to keep
    }
};
