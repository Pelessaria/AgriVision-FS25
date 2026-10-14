<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class HostAccess extends Model
{
    protected $fillable = [
        'workspace_id',
        'protocol',
        'host',
        'port',
        'username',
        'password_encrypted',
        'remote_root',
        'remote_savegame_path',
        'remote_mod_settings_path',
        'insecure_acknowledged_at',
        'last_verified_at',
        'last_error',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'insecure_acknowledged_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function setPassword(?string $password): void
    {
        $this->password_encrypted = $password === null || $password === ''
            ? null
            : Crypt::encryptString($password);
    }

    public function password(): ?string
    {
        if ($this->password_encrypted === null || $this->password_encrypted === '') {
            return null;
        }

        return Crypt::decryptString($this->password_encrypted);
    }

    public function hasPassword(): bool
    {
        return filled($this->password_encrypted);
    }
}
