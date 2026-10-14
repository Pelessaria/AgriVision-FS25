<?php

namespace App\Services\Fs25;

use App\Models\GameFarm;
use App\Models\Workspace;
use App\Models\WorkspaceSavegame;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Configure and sync a workspace against the Giants dedicated-server feed.
 * Feed codes stay encrypted on the model and never leave this service in responses.
 */
final class WorkspaceFeedService
{
    public function __construct(
        private readonly WorkspaceFeedSync $sync,
    ) {}

    public function configure(Workspace $workspace, ?string $feedUrl, bool $keepExistingCode = false): Workspace
    {
        $trimmed = trim((string) $feedUrl);

        if ($trimmed === '') {
            $workspace->feed_base_url = null;
            $workspace->setFeedCode(null);
            $workspace->link_state = 'unpaired';
            $workspace->data_mode = 'empty';
            $workspace->map_name = null;
            $workspace->game_version = null;
            $workspace->day_time_ms = null;
            $workspace->map_size = null;
            $workspace->players_online = null;
            $workspace->player_capacity = null;
            $workspace->status_note = null;
            $workspace->save();

            return $workspace;
        }

        try {
            if (str_contains($trimmed, 'code=')) {
                $client = DedicatedServerFeedClient::fromFeedUrl($trimmed);
                $workspace->feed_base_url = $client->baseUrl();
                $workspace->setFeedCode($client->code());
            } else {
                if (! $keepExistingCode || $workspace->feedCode() === null) {
                    throw new InvalidArgumentException(
                        'Feed-URL muss ?code=… enthalten (oder der Code ist bereits gespeichert).'
                    );
                }
                $client = DedicatedServerFeedClient::fromParts($trimmed, $workspace->feedCode());
                $workspace->feed_base_url = $client->baseUrl();
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        }

        if ($workspace->link_state === 'unpaired') {
            $workspace->link_state = 'offline';
        }
        $workspace->status_note = null;
        $workspace->save();

        return $workspace;
    }

    /**
     * @param  bool  $light  Stats-only probe (short timeout). Full sync pulls savegames too.
     */
    public function sync(Workspace $workspace, bool $light = false): Workspace
    {
        if ($workspace->lifecycle === 'disabled') {
            throw new RuntimeException('Workspace is disabled.');
        }

        $base = trim((string) $workspace->feed_base_url);
        $code = $workspace->feedCode();
        if ($base === '' || $code === null || $code === '') {
            throw new RuntimeException('Feed is not configured.');
        }

        try {
            $timeout = $light ? 4 : 20;
            $client = DedicatedServerFeedClient::fromParts($base, $code, $timeout);
            $snapshot = $light ? $client->fetchLightSnapshot() : $client->fetchSnapshot();
            $workspace = $this->sync->apply($workspace, $snapshot);

            if (! $light) {
                // Full snapshot already loaded vehicles savegame XML into counts; re-fetch for farm IDs.
                $vehiclesXml = $client->fetchSavegame('vehicles');
                $this->syncFarmStubs($workspace, self::farmIdsFromVehiclesXml($vehiclesXml));
            }

            return $workspace;
        } catch (Throwable $e) {
            $workspace->link_state = 'offline';
            $workspace->status_note = mb_substr($e->getMessage(), 0, 240);
            $workspace->last_sync_at = now();
            $workspace->save();

            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Discover farm stubs from savegame vehicles.xml (IDs only; names via FTP/mod later).
     *
     * @return list<array<string, mixed>>
     */
    public function syncFarmsFromFeed(Workspace $workspace): array
    {
        $base = trim((string) $workspace->feed_base_url);
        $code = $workspace->feedCode();
        if ($base === '' || $code === null || $code === '') {
            return $this->serializeFarms($workspace);
        }

        try {
            $client = DedicatedServerFeedClient::fromParts($base, $code, 8);
            $vehiclesXml = $client->fetchSavegame('vehicles');
            $this->syncFarmStubs($workspace, self::farmIdsFromVehiclesXml($vehiclesXml));
        } catch (Throwable) {
            // Keep previously known stubs when host is briefly unreachable.
        }

        return $this->serializeFarms($workspace);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFarms(Workspace $workspace): array
    {
        return $this->serializeFarms($workspace);
    }

    /**
     * Live vehicles from stats JSON + farm stub discovery from savegame.
     *
     * @return array{ok: bool, error: string|null, fetchedAt: string|null, vehicles: list<array<string, mixed>>, farms: list<array<string, mixed>>}
     */
    public function fetchVehicles(Workspace $workspace): array
    {
        if ($workspace->lifecycle === 'disabled') {
            return [
                'ok' => false,
                'error' => 'Workspace is disabled.',
                'fetchedAt' => null,
                'vehicles' => [],
                'farms' => $this->serializeFarms($workspace),
            ];
        }

        $base = trim((string) $workspace->feed_base_url);
        $code = $workspace->feedCode();
        if ($base === '' || $code === null || $code === '') {
            return [
                'ok' => false,
                'error' => 'Feed is not configured.',
                'fetchedAt' => null,
                'vehicles' => [],
                'farms' => [],
            ];
        }

        try {
            $client = DedicatedServerFeedClient::fromParts($base, $code, 8);
            $snapshot = $client->fetchLightSnapshot();
            $this->sync->apply($workspace, $snapshot);

            try {
                $vehiclesXml = $client->fetchSavegame('vehicles');
                $this->syncFarmStubs($workspace, self::farmIdsFromVehiclesXml($vehiclesXml));
            } catch (Throwable) {
                // Positions still useful without farm stubs.
            }

            $vehicles = [];
            foreach ($snapshot->vehicles as $index => $vehicle) {
                $vehicles[] = [
                    'id' => 'feed-'.$workspace->public_id.'-'.$index,
                    'workspaceId' => $workspace->public_id,
                    'name' => $vehicle['name'] ?? 'Fahrzeug',
                    'category' => self::mapVehicleCategory($vehicle['category'] ?? null, $vehicle['type'] ?? null),
                    // Stats JSON has no farmId; savegame matching comes later.
                    'farmId' => null,
                    'iconUrl' => null,
                    'x' => $vehicle['x'] ?? null,
                    'y' => $vehicle['y'] ?? null,
                    'z' => $vehicle['z'] ?? null,
                    'fill' => null,
                    'hours' => null,
                    'observedAt' => $snapshot->fetchedAtUtc,
                ];
            }

            return [
                'ok' => true,
                'error' => null,
                'fetchedAt' => $snapshot->fetchedAtUtc,
                'vehicles' => $vehicles,
                'farms' => $this->serializeFarms($workspace),
            ];
        } catch (Throwable $e) {
            $workspace->link_state = 'offline';
            $workspace->status_note = mb_substr($e->getMessage(), 0, 240);
            $workspace->last_sync_at = now();
            $workspace->save();

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'fetchedAt' => null,
                'vehicles' => [],
                'farms' => $this->serializeFarms($workspace),
            ];
        }
    }

    public function fetchMapJpeg(Workspace $workspace, int $quality = 45, int $size = 384): string
    {
        $base = trim((string) $workspace->feed_base_url);
        $code = $workspace->feedCode();
        if ($base === '' || $code === null || $code === '') {
            throw new RuntimeException('Feed is not configured.');
        }

        $client = DedicatedServerFeedClient::fromParts($base, $code, 4);

        return $client->fetchMapJpeg($quality, $size);
    }

    /**
     * @param  list<int>  $gameFarmIds
     */
    public function syncFarmStubs(Workspace $workspace, array $gameFarmIds): void
    {
        $ids = array_values(array_unique(array_filter(
            $gameFarmIds,
            static fn (mixed $id): bool => is_int($id) && $id > 0
        )));

        $activeSavegameId = WorkspaceSavegame::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->value('id');

        foreach ($ids as $gameFarmId) {
            GameFarm::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'workspace_savegame_id' => $activeSavegameId,
                    'game_farm_id' => $gameFarmId,
                ],
                [
                    'display_name' => null,
                    'money' => null,
                ]
            );
        }

        if ($ids === []) {
            return;
        }

        // Drop auto-stubs that vanished; keep rows that already have a real name (FTP/mod).
        GameFarm::query()
            ->where('workspace_id', $workspace->id)
            ->where('workspace_savegame_id', $activeSavegameId)
            ->whereNull('display_name')
            ->whereNotIn('game_farm_id', $ids)
            ->delete();
    }

    /**
     * @return list<int>
     */
    public static function farmIdsFromVehiclesXml(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        if (! preg_match_all('/\bfarmId="(\d+)"/i', $xml, $matches)) {
            return [];
        }

        $ids = array_map(static fn (string $v): int => (int) $v, $matches[1]);

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeFarms(Workspace $workspace): array
    {
        return GameFarm::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('game_farm_id')
            ->get()
            ->map(static function (GameFarm $farm) use ($workspace): array {
                return [
                    'id' => $workspace->public_id.'-f'.$farm->game_farm_id,
                    'workspaceId' => $workspace->public_id,
                    'gameFarmId' => $farm->game_farm_id,
                    'name' => $farm->display_name,
                    'money' => $farm->money,
                    'hectares' => $farm->hectares,
                    'animals' => $farm->animals,
                    'storageFill' => $farm->storage_fill,
                ];
            })
            ->values()
            ->all();
    }

    private static function mapVehicleCategory(?string $category, ?string $type): string
    {
        $hay = strtolower(trim(($category ?? '').' '.($type ?? '')));
        if (str_contains($hay, 'harvest') || str_contains($hay, 'combine')) {
            return 'harvester';
        }
        if (str_contains($hay, 'tractor')) {
            return 'tractor';
        }
        if (str_contains($hay, 'trailer') || str_contains($hay, 'wagon') || str_contains($hay, 'tipper')) {
            return 'trailer';
        }

        return 'tool';
    }
}
