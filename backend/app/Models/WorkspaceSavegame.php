<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceSavegame extends Model
{
    protected $fillable = [
        'workspace_id',
        'slot_key',
        'remote_path',
        'display_name',
        'status',
        'status_note',
        'has_farms_xml',
        'has_career_xml',
        'is_active',
        'last_probed_at',
    ];

    protected function casts(): array
    {
        return [
            'has_farms_xml' => 'boolean',
            'has_career_xml' => 'boolean',
            'is_active' => 'boolean',
            'last_probed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function farms(): HasMany
    {
        return $this->hasMany(GameFarm::class, 'workspace_savegame_id');
    }
}
