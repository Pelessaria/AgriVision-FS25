<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_savegames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            /** Folder key e.g. savegame, savegame1 */
            $table->string('slot_key', 64);
            $table->string('remote_path');
            $table->string('display_name')->nullable();
            /** ready|no_farms|error|unknown */
            $table->string('status', 24)->default('unknown');
            $table->string('status_note')->nullable();
            $table->boolean('has_farms_xml')->default(false);
            $table->boolean('has_career_xml')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_probed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'slot_key']);
        });

        Schema::table('game_farms', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_savegame_id')->nullable()->after('workspace_id');
            $table->foreign('workspace_savegame_id')
                ->references('id')
                ->on('workspace_savegames')
                ->nullOnDelete();
            $table->dropUnique(['workspace_id', 'game_farm_id']);
            // Per-savegame uniqueness; feed stubs keep savegame_id null.
            $table->unique(['workspace_savegame_id', 'game_farm_id']);
        });
    }

    public function down(): void
    {
        Schema::table('game_farms', function (Blueprint $table) {
            $table->dropUnique(['workspace_savegame_id', 'game_farm_id']);
            $table->dropForeign(['workspace_savegame_id']);
            $table->dropColumn('workspace_savegame_id');
            $table->unique(['workspace_id', 'game_farm_id']);
        });

        Schema::dropIfExists('workspace_savegames');
    }
};
