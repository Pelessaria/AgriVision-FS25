<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent schema for workspace_savegames + game_farms.savegame link.
 */
final class WorkspaceSavegamesSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('workspace_savegames')) {
            Schema::create('workspace_savegames', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->string('slot_key', 64);
                $table->string('remote_path');
                $table->string('display_name')->nullable();
                $table->string('status', 24)->default('unknown');
                $table->string('status_note')->nullable();
                $table->boolean('has_farms_xml')->default(false);
                $table->boolean('has_career_xml')->default(false);
                $table->boolean('is_active')->default(false);
                $table->timestamp('last_probed_at')->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'slot_key']);
            });
        }

        if (! Schema::hasTable('game_farms')) {
            return;
        }

        if (! Schema::hasColumn('game_farms', 'workspace_savegame_id')) {
            Schema::table('game_farms', function (Blueprint $table) {
                $table->unsignedBigInteger('workspace_savegame_id')->nullable();
            });
        }

        // MariaDB may back the workspace_id FK with the composite unique
        // (workspace_id, game_farm_id). Add a dedicated index before dropping it.
        self::ensurePlainIndex('game_farms', 'game_farms_workspace_id_index', ['workspace_id']);
        self::dropIndexIfExists('game_farms', 'game_farms_workspace_id_game_farm_id_unique');

        self::ensureForeignKey();
        self::ensureUnique('game_farms', 'game_farms_workspace_savegame_id_game_farm_id_unique', [
            'workspace_savegame_id',
            'game_farm_id',
        ]);
    }

    private static function ensureForeignKey(): void
    {
        if (self::foreignExists('game_farms', 'game_farms_workspace_savegame_id_foreign')) {
            return;
        }

        Schema::table('game_farms', function (Blueprint $table) {
            $table->foreign('workspace_savegame_id')
                ->references('id')
                ->on('workspace_savegames')
                ->nullOnDelete();
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private static function ensureUnique(string $table, string $indexName, array $columns): void
    {
        if (self::indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->unique($columns, $indexName);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private static function ensurePlainIndex(string $table, string $indexName, array $columns): void
    {
        if (self::indexExists($table, $indexName)) {
            return;
        }

        // Another single-column index on the same column is enough (e.g. FK index).
        if (count($columns) === 1 && self::hasSingleColumnIndex($table, $columns[0])) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->index($columns, $indexName);
        });
    }

    private static function hasSingleColumnIndex(string $table, string $column): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }

        // Load full index definitions — filtering by column alone mis-detects
        // composite uniques that only lead with $column (MariaDB FK 1553).
        $rows = DB::select('SHOW INDEX FROM `'.$table.'`');
        $byKey = [];
        foreach ($rows as $row) {
            $name = (string) ($row->Key_name ?? '');
            $seq = (int) ($row->Seq_in_index ?? 0);
            $col = (string) ($row->Column_name ?? '');
            $byKey[$name][$seq] = $col;
        }
        foreach ($byKey as $cols) {
            ksort($cols);
            if (count($cols) === 1 && reset($cols) === $column) {
                return true;
            }
        }

        return false;
    }

    private static function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! self::indexExists($table, $indexName)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `'.$table.'` DROP INDEX `'.$indexName.'`');

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropUnique($indexName);
        });
    }

    private static function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }

        $rows = DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$indexName]);

        return $rows !== [];
    }

    private static function foreignExists(string $table, string $foreignName): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }

        $database = Schema::getConnection()->getDatabaseName();
        $rows = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$database, $table, $foreignName, 'FOREIGN KEY']
        );

        return $rows !== [];
    }
}
