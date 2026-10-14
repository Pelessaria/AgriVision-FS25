<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->unique()->after('name');
        });

        $users = DB::table('users')->select('id', 'email', 'name')->orderBy('id')->get();
        foreach ($users as $user) {
            $base = $this->suggestUsername((string) $user->email, (string) $user->name);
            $candidate = $base;
            $n = 2;
            while (DB::table('users')->where('username', $candidate)->where('id', '!=', $user->id)->exists()) {
                $candidate = $base.$n;
                $n++;
            }
            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }

    private function suggestUsername(string $email, string $name): string
    {
        $local = Str::before($email, '@');
        $raw = $local !== '' ? $local : $name;
        $slug = Str::lower(preg_replace('/[^a-zA-Z0-9._-]+/', '', $raw) ?? '');
        if ($slug === '' || strlen($slug) < 2) {
            $slug = 'user';
        }

        return Str::limit($slug, 64, '');
    }
};
