<?php

namespace App\Support;

use App\Models\GameFarm;
use App\Models\Workspace;
use App\Models\WorkspaceSavegame;
use Illuminate\Support\Collection;

/**
 * Farm list for a workspace: one row per game_farm_id for the active savegame.
 */
final class GameFarms
{
    /**
     * Drop legacy feed stubs (null savegame) when a savegame-bound farm exists.
     */
    public static function purgeDuplicateStubs(Workspace $workspace): void
    {
        $namedIds = GameFarm::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('workspace_savegame_id')
            ->pluck('game_farm_id');

        if ($namedIds->isEmpty()) {
            return;
        }

        GameFarm::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('workspace_savegame_id')
            ->whereIn('game_farm_id', $namedIds)
            ->delete();
    }

    /**
     * Attach null-savegame stubs to the active slot (avoids parallel Hof-N + named rows).
     */
    public static function adoptNullStubs(Workspace $workspace, ?int $savegameId): void
    {
        if ($savegameId === null) {
            return;
        }

        // Never bulk-update into a unique collision — drop stubs that already have a named row.
        self::purgeDuplicateStubs($workspace);

        GameFarm::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('workspace_savegame_id')
            ->update(['workspace_savegame_id' => $savegameId]);

        self::collapseSavegameDuplicates($workspace, $savegameId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function serialize(Workspace $workspace): array
    {
        self::purgeDuplicateStubs($workspace);

        $activeId = WorkspaceSavegame::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->value('id');

        $query = GameFarm::query()->where('workspace_id', $workspace->id);
        if ($activeId) {
            $query->where(function ($q) use ($activeId) {
                $q->where('workspace_savegame_id', $activeId)
                    ->orWhereNull('workspace_savegame_id');
            });
        }

        /** @var Collection<int, GameFarm> $rows */
        $rows = $query->orderBy('game_farm_id')->get();

        $best = [];
        foreach ($rows as $farm) {
            $id = (int) $farm->game_farm_id;
            if (! isset($best[$id]) || self::score($farm) > self::score($best[$id])) {
                $best[$id] = $farm;
            }
        }

        ksort($best, SORT_NUMERIC);

        return array_values(array_map(
            static function (GameFarm $farm) use ($workspace): array {
                return [
                    'id' => $workspace->public_id.'-f'.$farm->game_farm_id,
                    'workspaceId' => $workspace->public_id,
                    'savegameId' => $farm->workspace_savegame_id,
                    'gameFarmId' => $farm->game_farm_id,
                    'name' => $farm->display_name,
                    'money' => $farm->money,
                    'hectares' => $farm->hectares,
                    'animals' => $farm->animals,
                    'storageFill' => $farm->storage_fill,
                ];
            },
            $best
        ));
    }

    private static function score(GameFarm $farm): int
    {
        $score = 0;
        if ($farm->workspace_savegame_id !== null) {
            $score += 4;
        }
        if ($farm->display_name !== null && trim((string) $farm->display_name) !== '') {
            $score += 2;
        }
        if ($farm->money !== null) {
            $score += 1;
        }

        return $score;
    }

    private static function collapseSavegameDuplicates(Workspace $workspace, int $savegameId): void
    {
        $groups = GameFarm::query()
            ->where('workspace_id', $workspace->id)
            ->where('workspace_savegame_id', $savegameId)
            ->get()
            ->groupBy('game_farm_id');

        foreach ($groups as $rows) {
            if ($rows->count() < 2) {
                continue;
            }
            /** @var Collection<int, GameFarm> $rows */
            $ordered = $rows->sortByDesc(static fn (GameFarm $f): int => self::score($f))->values();
            $keep = $ordered->first();
            foreach ($ordered->slice(1) as $dup) {
                if ($keep && $dup->display_name && ! $keep->display_name) {
                    $keep->display_name = $dup->display_name;
                }
                if ($keep && $dup->money !== null && $keep->money === null) {
                    $keep->money = $dup->money;
                }
                $dup->delete();
            }
            $keep?->save();
        }
    }
}
