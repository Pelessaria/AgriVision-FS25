<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameFarm extends Model
{
    protected $fillable = [
        'workspace_id',
        'workspace_savegame_id',
        'game_farm_id',
        'display_name',
        'money',
        'hectares',
        'animals',
        'storage_fill',
    ];

    protected function casts(): array
    {
        return [
            'game_farm_id' => 'integer',
            'money' => 'integer',
            'hectares' => 'float',
            'animals' => 'integer',
            'storage_fill' => 'float',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function savegame(): BelongsTo
    {
        return $this->belongsTo(WorkspaceSavegame::class, 'workspace_savegame_id');
    }
}
