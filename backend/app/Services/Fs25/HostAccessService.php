<?php

namespace App\Services\Fs25;

use App\Models\GameFarm;
use App\Models\HostAccess;
use App\Models\Workspace;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Configure encrypted host file access and pull farms.xml.
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
     * @return array{ok: bool, error: string|null, access: HostAccess|null, farms: list<array<string, mixed>>}
     */
    public function probeAndSyncFarms(Workspace $workspace): array
    {
        $access = HostAccess::query()->where('workspace_id', $workspace->id)->first();
        if (! $access) {
            return ['ok' => false, 'error' => 'Dateizugang nicht konfiguriert.', 'access' => null, 'farms' => []];
        }

        $endpoint = strtoupper($access->protocol).' '.$access->host.':'.$access->port;

        try {
            $client = new HostFileClient($access);
            $endpoint = $client->endpointLabel();
            $probe = $client->probePaths(
                $access->remote_savegame_path,
                $access->remote_mod_settings_path,
            );

            if ($probe['savegame']) {
                $access->remote_savegame_path = $probe['savegame'];
            }
            if ($probe['modSettings']) {
                $access->remote_mod_settings_path = $probe['modSettings'];
            }

            if (! $access->remote_savegame_path) {
                $hint = $probe['candidates'] !== []
                    ? ' Gefundene Ordner: '.implode(', ', array_slice($probe['candidates'], 0, 8))
                    : '';
                $access->status = 'error';
                $access->last_error = HostConnectionError::redact(
                    "Savegame-Ordner nicht gefunden ({$endpoint}). Pfad manuell setzen.{$hint}"
                );
                $access->save();

                return [
                    'ok' => false,
                    'error' => $access->last_error,
                    'access' => $access->fresh(),
                    'farms' => $this->serializeFarms($workspace),
                ];
            }

            $farmsPath = rtrim($access->remote_savegame_path, '/').'/farms.xml';
            if (! $client->fileExists($farmsPath)) {
                $access->status = 'error';
                $access->last_error = HostConnectionError::redact(
                    "farms.xml fehlt unter {$farmsPath} ({$endpoint})."
                );
                $access->last_verified_at = now();
                $access->save();

                return [
                    'ok' => false,
                    'error' => $access->last_error,
                    'access' => $access->fresh(),
                    'farms' => $this->serializeFarms($workspace),
                ];
            }

            $xml = $client->read($farmsPath);
            $parsed = FarmsXmlParser::parse($xml);
            $this->applyFarms($workspace, $parsed);

            $access->status = 'ok';
            $access->last_error = null;
            $access->last_verified_at = now();
            $access->save();

            return [
                'ok' => true,
                'error' => null,
                'access' => $access->fresh(),
                'farms' => $this->serializeFarms($workspace),
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
            ];
        }
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
     * @param  list<array{gameFarmId: int, name: string|null, money: int|null}>  $parsed
     */
    private function applyFarms(Workspace $workspace, array $parsed): void
    {
        $seen = [];
        foreach ($parsed as $farm) {
            $id = (int) $farm['gameFarmId'];
            if ($id <= 0) {
                continue;
            }
            $seen[] = $id;
            $row = GameFarm::query()->firstOrNew([
                'workspace_id' => $workspace->id,
                'game_farm_id' => $id,
            ]);
            if ($farm['name'] !== null && $farm['name'] !== '') {
                $row->display_name = $farm['name'];
            }
            if ($farm['money'] !== null) {
                $row->money = $farm['money'];
            }
            $row->save();
        }

        // Keep feed-only stubs that aren't in farms.xml (unlikely), don't mass-delete.
        unset($seen);
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

    private function nullablePath(mixed $value): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $value));
        $path = trim($path, '/');

        return $path === '' ? null : $path;
    }

}
