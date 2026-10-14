<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained('workspaces')->cascadeOnDelete();
            $table->string('protocol', 16); // sftp|ftps|ftp
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('username');
            $table->text('password_encrypted')->nullable();
            $table->string('remote_root')->nullable();
            $table->string('remote_savegame_path')->nullable();
            $table->string('remote_mod_settings_path')->nullable();
            $table->timestamp('insecure_acknowledged_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_error')->nullable();
            $table->string('status', 24)->default('unconfigured'); // unconfigured|ok|error
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_accesses');
    }
};
