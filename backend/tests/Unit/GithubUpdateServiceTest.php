<?php

namespace Tests\Unit;

use App\Services\Update\GithubUpdateService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubUpdateServiceTest extends TestCase
{
    public function test_status_reports_update_when_sha_differs(): void
    {
        config([
            'agrivision.github.owner' => 'Pelessaria',
            'agrivision.github.repo' => 'AgriVision-FS25',
            'agrivision.github.branch' => 'main',
            'agrivision.version' => '0.3.0',
            'agrivision.updates.enabled' => true,
        ]);

        Http::fake([
            'api.github.com/repos/Pelessaria/AgriVision-FS25/commits/main' => Http::response([
                'sha' => 'abcdef1234567890abcdef1234567890abcdef12',
                'html_url' => 'https://github.com/Pelessaria/AgriVision-FS25/commit/abcdef12',
                'commit' => ['message' => "Fix updater\n\nDetails"],
            ], 200),
        ]);

        $status = app(GithubUpdateService::class)->status();

        $this->assertTrue($status['updateAvailable']);
        $this->assertSame('Pelessaria/AgriVision-FS25', $status['repo']);
        $this->assertSame('Fix updater', $status['remoteMessage']);
        $this->assertSame('abcdef1234567890abcdef1234567890abcdef12', $status['remoteSha']);
    }
}
