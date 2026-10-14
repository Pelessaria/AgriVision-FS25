<?php

namespace Tests\Unit;

use App\Services\Fs25\HostFileClient;
use PHPUnit\Framework\TestCase;

class SavegamePathPreferenceTest extends TestCase
{
    public function test_prefers_live_slot_over_backup_nested_copy(): void
    {
        $this->assertSame(
            'savegame2',
            HostFileClient::preferSavegamePath('savegame2_backup/savegame2', 'savegame2')
        );
        $this->assertSame(
            'savegame2',
            HostFileClient::preferSavegamePath('savegame2', 'savegame2_backup/savegame2')
        );
    }

    public function test_keeps_backup_when_it_is_the_only_candidate(): void
    {
        $this->assertSame(
            'savegame2_backup/savegame2',
            HostFileClient::preferSavegamePath(null, 'savegame2_backup/savegame2')
        );
    }

    public function test_prefers_shallower_path_when_neither_is_backup(): void
    {
        $this->assertSame(
            'savegame1',
            HostFileClient::preferSavegamePath('My Games/FarmingSimulator25/savegame1', 'savegame1')
        );
    }

    public function test_detects_backupish_path_segments(): void
    {
        $this->assertTrue(HostFileClient::pathLooksLikeBackup('savegame2_backup/savegame2'));
        $this->assertTrue(HostFileClient::pathLooksLikeBackup('backups/savegame1'));
        $this->assertTrue(HostFileClient::pathLooksLikeBackup('savegame1_old'));
        $this->assertFalse(HostFileClient::pathLooksLikeBackup('savegame2'));
        $this->assertFalse(HostFileClient::pathLooksLikeBackup('Documents/My Games/savegame1'));
    }
}
