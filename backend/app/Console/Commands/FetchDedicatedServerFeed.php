<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Fs25\DedicatedServerFeedClient;
use App\Services\Fs25\WorkspaceFeedSync;
use Illuminate\Console\Command;
use Throwable;

class FetchDedicatedServerFeed extends Command
{
    protected $signature = 'fs25:feed-fetch
        {--base= : Dedicated server origin, e.g. https://host:port}
        {--code= : Feed code}
        {--url= : Full feed URL including ?code=}
        {--workspace= : Workspace public_id or numeric id to update after fetch}
        {--out= : Optional path under storage/app to write JSON summary}
        {--map= : Optional path under storage/app to write map JPEG}';

    protected $description = 'Fetch dedicated-server web API stats/savegames (read-only). Never prints the feed code.';

    public function handle(WorkspaceFeedSync $sync): int
    {
        try {
            $client = $this->makeClient();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->line('Provide --url=…?code=… or --base=… and --code=… (or env FS25_FEED_BASE / FS25_FEED_CODE).');

            return self::FAILURE;
        }

        $this->info('Fetching feed from '.$client->baseUrl().' …');

        try {
            $snapshot = $client->fetchSnapshot();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $summary = $snapshot->toArray();
        $this->table(
            ['Field', 'Value'],
            [
                ['linkState', $summary['linkState']],
                ['serverName', $summary['serverName'] ?? '—'],
                ['mapName', $summary['mapName'] ?? '—'],
                ['mapSize', $summary['mapSize'] ?? '—'],
                ['gameVersion', $summary['gameVersion'] ?? '—'],
                ['players', ($summary['playersOnline'] ?? '—').' / '.($summary['playerCapacity'] ?? '—')],
                ['mods', (string) $summary['modCount']],
                ['vehicles (stats)', (string) $summary['vehicleCountStats']],
                ['vehicles (savegame)', (string) $summary['vehicleCountSavegame']],
                ['economy fillTypes', (string) $summary['economyFillTypes']],
                ['career money', $summary['careerMoney'] ?? '—'],
                ['fetchedAtUtc', $summary['fetchedAtUtc']],
            ]
        );

        if ($workspaceKey = $this->option('workspace')) {
            $workspace = Workspace::query()
                ->where(function ($query) use ($workspaceKey) {
                    $query->where('public_id', $workspaceKey)
                        ->orWhere('id', $workspaceKey);
                })
                ->first();

            if (! $workspace) {
                $this->error('Workspace not found: '.$workspaceKey);

                return self::FAILURE;
            }

            $sync->apply($workspace, $snapshot);
            $this->info('Updated workspace '.$workspace->public_id.' (link_state='.$workspace->link_state.')');
        }

        if ($out = $this->option('out')) {
            $relative = ltrim(str_replace('\\', '/', $out), '/');
            $path = storage_path('app/'.$relative);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Wrote summary to storage/app/'.$relative);
        }

        if ($map = $this->option('map')) {
            try {
                $jpeg = $client->fetchMapJpeg();
                $relative = ltrim(str_replace('\\', '/', $map), '/');
                $path = storage_path('app/'.$relative);
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0775, true);
                }
                file_put_contents($path, $jpeg);
                $this->info('Wrote map JPEG to storage/app/'.$relative.' ('.strlen($jpeg).' bytes)');
            } catch (Throwable $e) {
                $this->warn('Map fetch failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function makeClient(): DedicatedServerFeedClient
    {
        $url = (string) ($this->option('url') ?: '');
        if ($url !== '') {
            return DedicatedServerFeedClient::fromFeedUrl($url);
        }

        $base = (string) ($this->option('base') ?: config('fs25.feed.base', ''));
        $code = (string) ($this->option('code') ?: config('fs25.feed.code', ''));

        return DedicatedServerFeedClient::fromParts($base, $code);
    }
}
