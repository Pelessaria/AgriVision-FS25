<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_farms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedInteger('game_farm_id');
            /** Null until farms.xml / connector supplies a name. */
            $table->string('display_name')->nullable();
            $table->bigInteger('money')->nullable();
            $table->decimal('hectares', 10, 2)->nullable();
            $table->unsignedInteger('animals')->nullable();
            $table->decimal('storage_fill', 5, 4)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'game_farm_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_farms');
    }
};
