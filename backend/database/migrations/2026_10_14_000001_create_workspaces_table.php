<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('display_name');
            $table->unsignedInteger('number')->default(1);
            $table->string('lifecycle', 32)->default('active'); // active|disabled
            $table->string('link_state', 32)->default('unpaired');
            $table->string('data_mode', 16)->default('empty'); // live|demo|empty
            $table->string('map_name')->nullable();
            $table->string('game_version')->nullable();
            $table->unsignedInteger('map_size')->nullable();
            $table->unsignedInteger('players_online')->nullable();
            $table->unsignedInteger('player_capacity')->nullable();
            $table->string('connector_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('status_note')->nullable();
            /** Feed origin only (no code). */
            $table->string('feed_base_url')->nullable();
            /** Encrypted feed code — never expose to the browser. */
            $table->text('feed_code_encrypted')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
