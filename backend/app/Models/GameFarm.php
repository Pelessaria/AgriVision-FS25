<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameFarm extends Model
{
    protected $fillable = [
        'workspace_id',
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
}
