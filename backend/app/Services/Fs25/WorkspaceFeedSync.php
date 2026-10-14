<?php

namespace App\Services\Fs25;

use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Applies a feed snapshot onto a workspace row (metadata only; no feed code).
 */
final class WorkspaceFeedSync
{
    public function apply(Workspace $workspace, FeedSnapshot $snapshot): Workspace
    {
        $workspace->fill([
            'link_state' => $snapshot->linkState,
            'data_mode' => 'live',
            'map_name' => $snapshot->mapName,
            'game_version' => $snapshot->gameVersion,
            'day_time_ms' => $snapshot->dayTimeMs,
            'map_size' => $snapshot->mapSize,
            'players_online' => $snapshot->playersOnline,
            'player_capacity' => $snapshot->playerCapacity,
            'last_seen_at' => Carbon::parse($snapshot->fetchedAtUtc),
            'last_sync_at' => Carbon::parse($snapshot->fetchedAtUtc),
            'status_note' => null,
            'feed_base_url' => $snapshot->baseUrl,
        ]);

        if ($workspace->display_name === '' || $workspace->display_name === 'Workspace') {
            if ($snapshot->serverName) {
                $workspace->display_name = $snapshot->serverName;
            }
        }

        $workspace->save();

        return $workspace;
    }
}
