<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Workspace::query()->orderBy('number')->orderBy('id')->get();

        return response()->json([
            'workspaces' => $rows->map(fn (Workspace $w) => $this->serialize($w))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:40'],
        ]);

        $maxNumber = (int) Workspace::query()->max('number');
        $workspace = Workspace::query()->create([
            'display_name' => $data['name'],
            'number' => $maxNumber + 1,
            'lifecycle' => 'active',
            'link_state' => 'unpaired',
            'data_mode' => 'empty',
        ]);

        $pairingCode = Str::upper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));

        return response()->json([
            'workspace' => $this->serialize($workspace),
            'pairingCode' => $pairingCode,
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Workspace $w): array
    {
        $disabled = $w->lifecycle === 'disabled';
        $notice = null;
        if ($disabled) {
            $notice = 'disabled';
        } elseif ($w->link_state === 'unpaired') {
            $notice = 'unpaired';
        } elseif ($w->feed_base_url === null || $w->feed_base_url === '') {
            $notice = 'feed';
        }

        return [
            'id' => $w->public_id,
            'number' => $w->number,
            'name' => $w->display_name,
            'linkState' => $w->link_state,
            'disabled' => $disabled,
            'mapName' => $w->map_name,
            'playersOnline' => $w->players_online,
            'playerCapacity' => $w->player_capacity,
            'gameTime' => null,
            'lastSeenAt' => optional($w->last_seen_at)?->toIso8601String(),
            'lastSyncAt' => optional($w->last_sync_at)?->toIso8601String(),
            'connectorVersion' => $w->connector_version,
            'notice' => $notice,
            'feedUrl' => $w->feed_base_url ?? '',
            'dataMode' => $w->data_mode,
        ];
    }
}
