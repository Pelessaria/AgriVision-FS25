<?php

namespace Tests\Unit;

use App\Services\Fs25\DedicatedServerFeedClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DedicatedServerFeedClientTest extends TestCase
{
    public function test_from_feed_url_extracts_origin_and_code(): void
    {
        $client = DedicatedServerFeedClient::fromFeedUrl('https://host.example:8080/feed/dedicated-server-stats.json?code=secret');
        $this->assertSame('https://host.example:8080', $client->baseUrl());
    }

    public function test_fetch_snapshot_uses_http_fake_and_omits_code(): void
    {
        Http::fake([
            'https://host.example:8080/feed/dedicated-server-stats.json*' => Http::response([
                'server' => ['name' => 'Demo', 'mapName' => 'Map', 'mapSize' => 2048, 'version' => '1.0'],
                'Slots' => ['capacity' => 8, 'numUsed' => 1],
                'Mods' => ['Mod' => []],
                'Vehicles' => ['Vehicle' => []],
            ], 200),
            'https://host.example:8080/feed/dedicated-server-savegame.html*file=careerSavegame*' => Http::response(
                '<careerSavegame><statistics><money>9</money></statistics></careerSavegame>',
                200
            ),
            'https://host.example:8080/feed/dedicated-server-savegame.html*file=vehicles*' => Http::response(
                '<vehicles><vehicle/></vehicles>',
                200
            ),
            'https://host.example:8080/feed/dedicated-server-savegame.html*file=economy*' => Http::response(
                '<economy></economy>',
                200
            ),
        ]);

        $client = DedicatedServerFeedClient::fromParts('https://host.example:8080', 'secret-code');
        $snapshot = $client->fetchSnapshot();
        $payload = $snapshot->toArray();

        $this->assertSame('Demo', $payload['serverName']);
        $this->assertSame(1, $payload['vehicleCountSavegame']);
        $this->assertStringNotContainsString('secret-code', json_encode($payload));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'code=secret-code');
        });
    }
}
