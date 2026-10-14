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

    public function sync(Workspace $workspace): Workspace
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
            $client = DedicatedServerFeedClient::fromParts($base, $code);
            $snapshot = $client->fetchSnapshot();

            return $this->sync->apply($workspace, $snapshot);
        } catch (Throwable $e) {
            $workspace->link_state = 'offline';
            $workspace->status_note = mb_substr($e->getMessage(), 0, 240);
            $workspace->last_sync_at = now();
            $workspace->save();

            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public function fetchMapJpeg(Workspace $workspace, int $quality = 45, int $size = 384): string
    {
        $base = trim((string) $workspace->feed_base_url);
        $code = $workspace->feedCode();
        if ($base === '' || $code === null || $code === '') {
            throw new RuntimeException('Feed is not configured.');
        }

        $client = DedicatedServerFeedClient::fromParts($base, $code);

        return $client->fetchMapJpeg($quality, $size);
    }
}
