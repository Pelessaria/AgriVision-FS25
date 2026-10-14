<?php

namespace App\Services\Fs25;

use App\Models\Workspace;
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

            return $this->sync->apply($workspace, $snapshot);
        } catch (Throwable $e) {
            $workspace->link_state = 'offline';
            $workspace->status_note = mb_substr($e->getMessage(), 0, 240);
            $workspace->last_sync_at = now();
            $workspace->save();

            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Live vehicles from stats JSON (light fetch). Empty list when host unreachable.
     *
     * @return array{ok: bool, error: string|null, fetchedAt: string|null, vehicles: list<array<string, mixed>>}
     */
    public function fetchVehicles(Workspace $workspace): array
    {
        if ($workspace->lifecycle === 'disabled') {
            return ['ok' => false, 'error' => 'Workspace is disabled.', 'fetchedAt' => null, 'vehicles' => []];
        }

        $base = trim((string) $workspace->feed_base_url);
        $code = $workspace->feedCode();
        if ($base === '' || $code === null || $code === '') {
            return ['ok' => false, 'error' => 'Feed is not configured.', 'fetchedAt' => null, 'vehicles' => []];
        }

        try {
            $client = DedicatedServerFeedClient::fromParts($base, $code, 4);
            $snapshot = $client->fetchLightSnapshot();
            $this->sync->apply($workspace, $snapshot);

            $vehicles = [];
            foreach ($snapshot->vehicles as $index => $vehicle) {
                $vehicles[] = [
                    'id' => 'feed-'.$workspace->public_id.'-'.$index,
                    'workspaceId' => $workspace->public_id,
                    'name' => $vehicle['name'] ?? 'Fahrzeug',
                    'category' => self::mapVehicleCategory($vehicle['category'] ?? null, $vehicle['type'] ?? null),
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
