<?php

use App\Models\Workspace;
use App\Services\Fs25\ConnectorSnapshotService;
use App\Services\InstanceSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('agrivision:sync-store-icons {--daemon : Keep pulling icons until the process is stopped}', function (ConnectorSnapshotService $icons) {
    if ($this->option('daemon')) {
        \App\Services\Fs25\IconSyncDaemon::writePid();
        $this->info('icon daemon '.getmypid());
    }

    $run = function (int $interval, int $budgetSec = 120) use ($icons): array {
        $pending = 0;
        $moved = 0;
        foreach (Workspace::query()->cursor() as $workspace) {
            $result = $icons->syncStoreIconsIfDue($workspace, $interval, $budgetSec);
            $pending += (int) $result['pending'];
            $moved += (int) $result['downloaded'] + (int) $result['converted'];
            $this->line(sprintf(
                '%s downloaded=%d converted=%d pending=%d listed=%d skipped=%s detail=%s',
                $workspace->public_id,
                $result['downloaded'],
                $result['converted'],
                $result['pending'],
                $result['listed'],
                $result['skipped'] ?? '-',
                $result['detail'] ?? '-'
            ));
            if (($result['skipped'] ?? null) === 'busy') {
                return ['pending' => $pending, 'moved' => 0];
            }
        }

        return ['pending' => $pending, 'moved' => $moved];
    };

    if (! $this->option('daemon')) {
        $deadline = time() + 480;
        do {
            $pass = $run(0, 120);
        } while ($pass['pending'] > 0 && $pass['moved'] > 0 && time() < $deadline);

        return;
    }

    while (true) {
        $deadline = time() + 480;
        $pass = ['pending' => 0, 'moved' => 0];
        try {
            do {
                $pass = $run(0, 120);
            } while ($pass['pending'] > 0 && $pass['moved'] > 0 && time() < $deadline);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
        sleep($pass['pending'] > 0 ? ConnectorSnapshotService::ICON_BACKLOG_INTERVAL_SEC : ConnectorSnapshotService::ICON_SYNC_INTERVAL_SEC);
    }
})->purpose('Pull missing vehicle store icons over FTP');

Schedule::command('agrivision:sync-store-icons')
    ->everyFiveMinutes()
    ->withoutOverlapping(20)
    ->appendOutputTo(storage_path('logs/icon-sync.log'));

Schedule::call(fn () => InstanceSchedule::touchHeartbeat())
    ->everyMinute()
    ->name('scheduler-heartbeat');

Artisan::command('agrivision:install-schedule', function () {
    $result = \App\Services\InstanceSchedule::install();
    $this->line($result['detail']);

    return $result['ok'] ? 0 : 1;
})->purpose('Install this directory\'s scheduler cron without touching other installations');

Artisan::command('agrivision:schedule-status', function () {
    $status = \App\Services\InstanceSchedule::status();
    $this->line(sprintf(
        'state=%s installed=%s daemon=%s last=%s',
        $status['state'],
        $status['installed'] ? 'yes' : 'no',
        $status['daemon'],
        $status['lastRunAt'] ?? '-'
    ));

    return $status['active'] ? 0 : 1;
})->purpose('Report whether this install\'s scheduler cron is installed and running');
