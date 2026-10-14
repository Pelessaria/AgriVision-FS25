<?php

namespace App\Services\Fs25;

use App\Models\GameFarm;
use App\Models\HostAccess;
use App\Models\Workspace;
use App\Models\WorkspaceSavegame;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Configure encrypted host file access and sync savegames / farms.xml.
 */
final class HostAccessService
{
    /**
     * @param  array{
     *   protocol?: string,
     *   host?: string,
     *   port?: int|null,
     *   username?: string,
     *   password?: string|null,
     *   keepPassword?: bool,
     *   remoteRoot?: string|null,
     *   remoteSavegamePath?: string|null,
     *   remoteModSettingsPath?: string|null,
     *   insecureAck?: bool
     * }  $data
     */
    public function configure(Workspace $workspace, array $data): HostAccess
    {
        $protocol = strtolower(trim((string) ($data['protocol'] ?? '')));
        if (! in_array($protocol, ['sftp', 'ftps', 'ftp'], true)) {
            throw new InvalidArgumentException('Protokoll muss sftp, ftps oder ftp sein.');
        }

        $host = trim((string) ($data['host'] ?? ''));
        if ($host === '') {
            throw new InvalidArgumentException('Host fehlt.');
        }

        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            throw new InvalidArgumentException('Benutzername fehlt.');
        }

        $port = $data['port'] ?? null;
        if ($port === null || $port === '' || (int) $port <= 0) {
            $port = $protocol === 'sftp' ? 22 : 21;
        }
        $port = (int) $port;

        if ($protocol === 'ftp' && empty($data['insecureAck'])) {
            throw new InvalidArgumentException('Unverschlüsseltes FTP erfordert die Bestätigung.');
        }

        $access = HostAccess::query()->firstOrNew(['workspace_id' => $workspace->id]);
        $access->protocol = $protocol;
        $access->host = $host;
        $access->port = $port;
        $access->username = $username;
        $access->remote_root = $this->nullablePath($data['remoteRoot'] ?? null);
        $access->remote_savegame_path = $this->nullablePath($data['remoteSavegamePath'] ?? null);
        $access->remote_mod_settings_path = $this->nullablePath($data['remoteModSettingsPath'] ?? null);
        $access->insecure_acknowledged_at = $protocol === 'ftp' ? now() : null;

        $password = $data['password'] ?? null;
        $keep = (bool) ($data['keepPassword'] ?? false);
        if (is_string($password) && $password !== '') {
            $access->setPassword($password);
        } elseif (! $keep || ! $access->exists || ! $access->hasPassword()) {
            throw new InvalidArgumentException('Passwort fehlt.');
        }

        $access->status = 'unconfigured';
        $access->last_error = null;
        $access->save();

        return $access->fresh();
    }

    public function clear(Workspace $workspace): void
    {
        HostAccess::query()->where('workspace_id', $workspace->id)->delete();
    }

    /**
     * @return array{
     *   ok: bool,
     *   error: string|null,
     *   access: HostAccess|null,
     *   farms: list<array<string, mixed>>,
     *   savegames: list<array<string, mixed>>
     * }
     */
    public function probeAndSyncFarms(Workspace $workspace): array
    {
        $access = HostAccess::query()->where('workspace_id', $workspace->id)->first();
        if (! $access) {
            return [
                'ok' => false,
                'error' => 'Dateizugang nicht konfiguriert.',
                'access' => null,
                'farms' => [],
                'savegames' => $this->serializeSavegames($workspace),
            ];
        }

        $endpoint = strtoupper($access->protocol).' '.$access->host.':'.$access->port;

        try {
            $client = new HostFileClient($access);
            $endpoint = $client->endpointLabel();
            $probe = $client->probePaths(
                $access->remote_savegame_path,
                $access->remote_mod_settings_path,
            );

            if ($probe['modSettings']) {
                $access->remote_mod_settings_path = $probe['modSettings'];
            }

            $discovered = $probe['savegames'] ?? [];
            if ($discovered === [] && $probe['savegame']) {
                $discovered = [[
                    'slotKey' => strtolower(basename(str_replace('\\', '/', $probe['savegame']))),
                    'path' => $probe['savegame'],
                ]];
            }

            if ($discovered === []) {
                $hint = $probe['candidates'] !== []
                    ? ' Gefundene Ordner: '.implode(', ', array_slice($probe['candidates'], 0, 8))
                    : '';
                $access->status = 'error';
                $access->last_error = HostConnectionError::redact(
                    "Kein Savegame-Ordner gefunden ({$endpoint}). Pfad manuell setzen.{$hint}"
                );
                $access->save();

                return [
                    'ok' => false,
                    'error' => $access->last_error,
                    'access' => $access->fresh(),
                    'farms' => $this->serializeFarms($workspace),
                    'savegames' => $this->serializeSavegames($workspace),
                ];
            }

            $seenKeys = [];
            foreach ($discovered as $item) {
                $slot = strtolower((string) $item['slotKey']);
                $path = (string) $item['path'];
                $seenKeys[] = $slot;
                $row = WorkspaceSavegame::query()->firstOrNew([
                    'workspace_id' => $workspace->id,
                    'slot_key' => $slot,
                ]);
                $row->remote_path = $path;
                $row->display_name = $row->display_name ?: $this->defaultSavegameLabel($slot);
                $this->refreshSavegameStatus($client, $row);
                $row->last_probed_at = now();
                $row->save();
            }

            // Keep rows that vanished from the host, but mark them.
            WorkspaceSavegame::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotIn('slot_key', $seenKeys)
                ->update([
                    'status' => 'unknown',
                    'status_note' => 'Beim letzten Scan nicht mehr gefunden.',
                    'is_active' => false,
                ]);

            $active = WorkspaceSavegame::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->first();

            if (! $active) {
                $active = WorkspaceSavegame::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('slot_key', $seenKeys)
                    ->orderByRaw("CASE WHEN status = 'ready' THEN 0 WHEN status = 'no_farms' THEN 1 ELSE 2 END")
                    ->orderBy('slot_key')
                    ->first();
                if ($active) {
                    $this->setActiveSavegame($workspace, $active);
                }
            }

            if ($active) {
                $this->syncFarmsForSavegame($client, $workspace, $active);
                $access->remote_savegame_path = $active->remote_path;
            } elseif (! empty($probe['savegame'])) {
                $access->remote_savegame_path = $probe['savegame'];
            }

            $access->status = 'ok';
            $access->last_error = null;
            $access->last_verified_at = now();
            $access->save();

            return [
                'ok' => true,
                'error' => null,
                'access' => $access->fresh(),
                'farms' => $this->serializeFarms($workspace),
                'savegames' => $this->serializeSavegames($workspace),
            ];
        } catch (Throwable $e) {
            $access->status = 'error';
            $access->last_error = HostConnectionError::format($e, "Dateizugang {$endpoint}");
            $access->save();

            return [
                'ok' => false,
                'error' => $access->last_error,
                'access' => $access->fresh(),
                'farms' => $this->serializeFarms($workspace),
                'savegames' => $this->serializeSavegames($workspace),
            ];
        }
    }

    /**
     * @return array{ok: bool, error: string|null, farms: list<array<string, mixed>>, savegames: list<array<string, mixed>>}
     */
    public function selectSavegame(Workspace $workspace, int|string $savegameId): array
    {
        $row = WorkspaceSavegame::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($q) use ($savegameId) {
                $q->where('id', $savegameId)->orWhere('slot_key', $savegameId);
            })
            ->first();

        if (! $row) {
            return [
                'ok' => false,
                'error' => 'Savegame nicht gefunden.',
                'farms' => $this->serializeFarms($workspace),
                'savegames' => $this->serializeSavegames($workspace),
            ];
        }

        $this->setActiveSavegame($workspace, $row);

        $access = HostAccess::query()->where('workspace_id', $workspace->id)->first();
        if ($access && $access->hasPassword()) {
            try {
                $client = new HostFileClient($access);
                $this->refreshSavegameStatus($client, $row);
                $row->last_probed_at = now();
                $row->save();
                $this->syncFarmsForSavegame($client, $workspace, $row);
                $access->remote_savegame_path = $row->remote_path;
                $access->save();
            } catch (Throwable $e) {
                $row->status = 'error';
                $row->status_note = HostConnectionError::format($e);
                $row->save();

                return [
                    'ok' => false,
                    'error' => $row->status_note,
                    'farms' => $this->serializeFarms($workspace),
                    'savegames' => $this->serializeSavegames($workspace),
                ];
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'farms' => $this->serializeFarms($workspace),
            'savegames' => $this->serializeSavegames($workspace),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function serialize(?HostAccess $access): ?array
    {
        if (! $access) {
            return null;
        }

        return [
            'configured' => true,
            'protocol' => $access->protocol,
            'host' => $access->host,
            'port' => $access->port,
            'username' => $access->username,
            'hasPassword' => $access->hasPassword(),
            'remoteRoot' => $access->remote_root,
            'remoteSavegamePath' => $access->remote_savegame_path,
            'remoteModSettingsPath' => $access->remote_mod_settings_path,
            'insecure' => $access->protocol === 'ftp',
            'status' => $access->status,
            'lastVerifiedAt' => optional($access->last_verified_at)?->toIso8601String(),
            'lastError' => $access->last_error,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serializeSavegames(Workspace $workspace): array
    {
        return WorkspaceSavegame::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('slot_key')
            ->get()
            ->map(static function (WorkspaceSavegame $sg): array {
                return [
                    'id' => $sg->id,
                    'slotKey' => $sg->slot_key,
                    'path' => $sg->remote_path,
                    'name' => $sg->display_name,
                    'status' => $sg->status,
                    'statusNote' => $sg->status_note,
                    'hasFarmsXml' => $sg->has_farms_xml,
                    'hasCareerXml' => $sg->has_career_xml,
                    'active' => $sg->is_active,
                    'lastProbedAt' => optional($sg->last_probed_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    private function setActiveSavegame(Workspace $workspace, WorkspaceSavegame $active): void
    {
        WorkspaceSavegame::query()
            ->where('workspace_id', $workspace->id)
            ->update(['is_active' => false]);
        $active->is_active = true;
        $active->save();
    }

    private function refreshSavegameStatus(HostFileClient $client, WorkspaceSavegame $row): void
    {
        $path = rtrim($row->remote_path, '/');
        $hasFarms = $client->fileExists($path.'/farms.xml');
        $hasCareer = $client->fileExists($path.'/careerSavegame.xml')
            || $client->fileExists($path.'/careerSavegame');

        $row->has_farms_xml = $hasFarms;
        $row->has_career_xml = $hasCareer;

        if ($hasFarms) {
            $row->status = 'ready';
            $row->status_note = 'farms.xml vorhanden';
        } else {
            $row->status = 'no_farms';
            $row->status_note = 'Kein farms.xml — Hofnamen später oder per Feed/Connector.';
        }

        if ($hasCareer && ($row->display_name === null || $row->display_name === $this->defaultSavegameLabel($row->slot_key))) {
            try {
                $career = $client->read($hasCareer && $client->fileExists($path.'/careerSavegame.xml')
                    ? $path.'/careerSavegame.xml'
                    : $path.'/careerSavegame');
                if (preg_match('/savegameName["\s:>]+([^<"\n]+)/i', $career, $m)
                    || preg_match('/<savegameName>([^<]+)<\/savegameName>/i', $career, $m)
                    || preg_match('/mapTitle="([^"]+)"/i', $career, $m)) {
                    $name = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($name !== '') {
                        $row->display_name = $name;
                    }
                }
            } catch (Throwable) {
                // optional metadata
            }
        }
    }

    private function syncFarmsForSavegame(HostFileClient $client, Workspace $workspace, WorkspaceSavegame $savegame): void
    {
        if (! $savegame->has_farms_xml) {
            return;
        }

        try {
            $xml = $client->read(rtrim($savegame->remote_path, '/').'/farms.xml');
            $parsed = FarmsXmlParser::parse($xml);
            $this->applyFarms($workspace, $savegame, $parsed);
            $savegame->status = 'ready';
            $savegame->status_note = count($parsed).' Höfe aus farms.xml';
            $savegame->save();
        } catch (Throwable $e) {
            $savegame->status = 'error';
            $savegame->status_note = HostConnectionError::format($e, 'farms.xml lesen');
            $savegame->save();
        }
    }

    /**
     * @param  list<array{gameFarmId: int, name: string|null, money: int|null}>  $parsed
     */
    private function applyFarms(Workspace $workspace, WorkspaceSavegame $savegame, array $parsed): void
    {
        foreach ($parsed as $farm) {
            $id = (int) $farm['gameFarmId'];
            if ($id <= 0) {
                continue;
            }
            $row = GameFarm::query()->firstOrNew([
                'workspace_savegame_id' => $savegame->id,
                'game_farm_id' => $id,
            ]);
            $row->workspace_id = $workspace->id;
            $row->workspace_savegame_id = $savegame->id;
            if ($farm['name'] !== null && $farm['name'] !== '') {
                $row->display_name = $farm['name'];
            }
            if ($farm['money'] !== null) {
                $row->money = $farm['money'];
            }
            $row->save();
        }
    }

    /**
     * Farms of the active savegame (or feed stubs without savegame).
     *
     * @return list<array<string, mixed>>
     */
    public function serializeFarms(Workspace $workspace): array
    {
        $active = WorkspaceSavegame::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->first();

        $query = GameFarm::query()->where('workspace_id', $workspace->id);
        if ($active) {
            $query->where(function ($q) use ($active) {
                $q->where('workspace_savegame_id', $active->id)
                    ->orWhereNull('workspace_savegame_id');
            });
        }

        return $query
            ->orderBy('game_farm_id')
            ->get()
            ->map(static function (GameFarm $farm) use ($workspace): array {
                return [
                    'id' => $workspace->public_id.'-f'.$farm->game_farm_id
                        .($farm->workspace_savegame_id ? '-s'.$farm->workspace_savegame_id : ''),
                    'workspaceId' => $workspace->public_id,
                    'savegameId' => $farm->workspace_savegame_id,
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

    private function defaultSavegameLabel(string $slot): string
    {
        if (preg_match('/^savegame(\d+)$/i', $slot, $m)) {
            return 'Savegame '.$m[1];
        }

        return 'Savegame';
    }

    private function nullablePath(mixed $value): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $value));
        $path = trim($path, '/');

        return $path === '' ? null : $path;
    }
}
