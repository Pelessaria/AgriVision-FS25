<?php

use App\Support\WorkspaceSavegamesSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        WorkspaceSavegamesSchema::ensure();
    }

    public function down(): void
    {
        if (Schema::hasTable('game_farms') && Schema::hasColumn('game_farms', 'workspace_savegame_id')) {
            Schema::table('game_farms', function (Blueprint $table) {
                try {
                    $table->dropForeign(['workspace_savegame_id']);
                } catch (\Throwable) {
                    // ignore
                }
                try {
                    $table->dropUnique('game_farms_workspace_savegame_id_game_farm_id_unique');
                } catch (\Throwable) {
                    // ignore
                }
                $table->dropColumn('workspace_savegame_id');
                $table->unique(['workspace_id', 'game_farm_id']);
            });
        }

        Schema::dropIfExists('workspace_savegames');
    }
};
