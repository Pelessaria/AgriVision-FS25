<?php

use App\Models\Workspace;
use App\Services\Fs25\ConnectorSnapshotService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('agrivision:sync-store-icons', function (ConnectorSnapshotService $icons) {
    foreach (Workspace::query()->cursor() as $workspace) {
        $result = $icons->syncStoreIconsIfDue($workspace, 20);
        $this->line(sprintf(
            '%s downloaded=%d converted=%d pending=%d skipped=%s detail=%s',
            $workspace->public_id,
            $result['downloaded'],
            $result['converted'],
            $result['pending'],
            $result['skipped'] ?? '-',
            $result['detail'] ?? '-'
        ));
    }
})->purpose('Pull missing vehicle store icons over FTP');

Schedule::command('agrivision:sync-store-icons')
    ->everyMinute()
    ->withoutOverlapping(10);
