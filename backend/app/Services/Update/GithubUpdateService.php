<?php

namespace App\Services\Update;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Shared-hosting-safe updater: downloads the public GitHub zipball and overlays
 * application files without Docker, Composer, or shell access.
 *
 * Preserves .env and storage/. Does not replace vendor/ (must ship in release ZIP
 * or stay as previously installed). Runs migrations after a successful overlay.
 */
final class GithubUpdateService
{
    private const STATE_FILE = 'update-state.json';

    /**
     * @return array{
     *   enabled: bool,
     *   installedVersion: string,
     *   installedSha: string|null,
     *   installedAt: string|null,
     *   repo: string,
     *   branch: string,
     *   remoteSha: string|null,
     *   remoteMessage: string|null,
     *   remoteHtmlUrl: string|null,
     *   updateAvailable: bool,
     *   checkedAt: string
     * }
     */
    public function status(): array
    {
        $state = $this->readState();
        $owner = (string) config('agrivision.github.owner');
        $repo = (string) config('agrivision.github.repo');
        $branch = (string) config('agrivision.github.branch');

        $remote = null;
        try {
            $remote = $this->fetchBranchHead($owner, $repo, $branch);
        } catch (Throwable) {
            $remote = null;
        }

        $installedSha = $state['sha'] ?? null;
        $remoteSha = $remote['sha'] ?? null;

        return [
            'enabled' => (bool) config('agrivision.updates.enabled'),
            'installedVersion' => (string) config('agrivision.version'),
            'installedSha' => $installedSha,
            'installedAt' => $state['installedAt'] ?? null,
            'repo' => $owner.'/'.$repo,
            'branch' => $branch,
            'remoteSha' => $remoteSha,
            'remoteMessage' => $remote['message'] ?? null,
            'remoteHtmlUrl' => $remote['html_url'] ?? null,
            'updateAvailable' => is_string($remoteSha)
                && ($installedSha === null || ! hash_equals($installedSha, $remoteSha)),
            'checkedAt' => gmdate('c'),
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   fromSha: string|null,
     *   toSha: string,
     *   filesWritten: int,
     *   migrationsRan: bool,
     *   message: string
     * }
     */
    public function apply(?string $confirmSha = null): array
    {
        if (! config('agrivision.updates.enabled')) {
            throw new RuntimeException('Updates are disabled on this installation.');
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required for updates.');
        }

        $owner = (string) config('agrivision.github.owner');
        $repo = (string) config('agrivision.github.repo');
        $branch = (string) config('agrivision.github.branch');
        $head = $this->fetchBranchHead($owner, $repo, $branch);
        $toSha = $head['sha'];

        if ($confirmSha !== null && $confirmSha !== '' && ! hash_equals($toSha, $confirmSha)) {
            throw new RuntimeException('Remote HEAD changed. Check for updates again.');
        }

        $state = $this->readState();
        $fromSha = $state['sha'] ?? null;
        if (is_string($fromSha) && hash_equals($fromSha, $toSha)) {
            return [
                'ok' => true,
                'fromSha' => $fromSha,
                'toSha' => $toSha,
                'filesWritten' => 0,
                'migrationsRan' => false,
                'message' => 'Already on the latest commit.',
            ];
        }

        $work = storage_path('app/'.trim((string) config('agrivision.updates.work_dir'), '/'));
        File::ensureDirectoryExists($work);
        $zipPath = $work.'/source.zip';
        $extractPath = $work.'/extract';

        if (File::exists($extractPath)) {
            File::deleteDirectory($extractPath);
        }
        File::ensureDirectoryExists($extractPath);

        $this->downloadZipball($owner, $repo, $toSha, $zipPath);

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Downloaded update archive could not be opened.');
        }

        if (! $zip->extractTo($extractPath)) {
            $zip->close();
            throw new RuntimeException('Update archive could not be extracted.');
        }
        $zip->close();

        $backendRoot = $this->locateBackendRoot($extractPath);
        $filesWritten = $this->overlayDirectory($backendRoot, base_path());

        $frontendDist = $this->locateFrontendDist($extractPath);
        if ($frontendDist !== null) {
            $filesWritten += $this->overlayDirectory($frontendDist, public_path('app'));
        }

        $migrationsRan = false;
        try {
            Artisan::call('migrate', ['--force' => true]);
            $migrationsRan = true;
        } catch (Throwable $e) {
            // Overlay already applied; surface migration failure clearly.
            $this->writeState([
                'sha' => $toSha,
                'installedAt' => gmdate('c'),
                'fromSha' => $fromSha,
                'filesWritten' => $filesWritten,
                'migrationError' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                'Files updated, but migrations failed: '.$e->getMessage(),
                0,
                $e
            );
        }

        try {
            Artisan::call('optimize:clear');
        } catch (Throwable) {
            // Optional on constrained hosts.
        }

        $this->writeState([
            'sha' => $toSha,
            'installedAt' => gmdate('c'),
            'fromSha' => $fromSha,
            'filesWritten' => $filesWritten,
            'migrationsRan' => $migrationsRan,
        ]);

        File::delete($zipPath);
        if (File::exists($extractPath)) {
            File::deleteDirectory($extractPath);
        }

        return [
            'ok' => true,
            'fromSha' => $fromSha,
            'toSha' => $toSha,
            'filesWritten' => $filesWritten,
            'migrationsRan' => $migrationsRan,
            'message' => 'Update applied from GitHub.',
        ];
    }

    /**
     * @return array{sha: string, message: string|null, html_url: string|null}
     */
    private function fetchBranchHead(string $owner, string $repo, string $branch): array
    {
        $response = $this->githubClient()
            ->get("https://api.github.com/repos/{$owner}/{$repo}/commits/{$branch}");

        if ($response->status() === 404) {
            throw new RuntimeException("GitHub repository or branch not found: {$owner}/{$repo}@{$branch}");
        }

        if (! $response->successful()) {
            throw new RuntimeException('GitHub API error HTTP '.$response->status());
        }

        $json = $response->json();
        $sha = is_array($json) ? (string) ($json['sha'] ?? '') : '';
        if ($sha === '' || strlen($sha) < 7) {
            throw new RuntimeException('GitHub response did not include a commit SHA.');
        }

        $message = null;
        $htmlUrl = null;
        if (is_array($json)) {
            $commit = is_array($json['commit'] ?? null) ? $json['commit'] : [];
            $message = isset($commit['message']) ? trim((string) $commit['message']) : null;
            if (is_string($message) && str_contains($message, "\n")) {
                $message = trim(explode("\n", $message, 2)[0]);
            }
            $htmlUrl = isset($json['html_url']) ? (string) $json['html_url'] : null;
        }

        return [
            'sha' => $sha,
            'message' => $message,
            'html_url' => $htmlUrl,
        ];
    }

    private function downloadZipball(string $owner, string $repo, string $sha, string $zipPath): void
    {
        $response = $this->githubClient()
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->sink($zipPath)
            ->get("https://api.github.com/repos/{$owner}/{$repo}/zipball/{$sha}");

        if (! $response->successful() || ! File::exists($zipPath) || File::size($zipPath) < 100) {
            throw new RuntimeException('GitHub zipball download failed (HTTP '.$response->status().').');
        }
    }

    private function githubClient(): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::timeout(120)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'AgriVision-Updater/0.3',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);

        $token = config('agrivision.github.token');
        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    private function locateBackendRoot(string $extractPath): string
    {
        $entries = File::directories($extractPath);
        if ($entries === []) {
            throw new RuntimeException('Update archive was empty.');
        }

        $root = $entries[0];
        $backend = $root.DIRECTORY_SEPARATOR.'backend';
        if (File::isDirectory($backend) && File::exists($backend.DIRECTORY_SEPARATOR.'artisan')) {
            return $backend;
        }

        if (File::exists($root.DIRECTORY_SEPARATOR.'artisan')) {
            return $root;
        }

        throw new RuntimeException('Could not locate Laravel backend/ inside the update archive.');
    }

    private function locateFrontendDist(string $extractPath): ?string
    {
        $entries = File::directories($extractPath);
        if ($entries === []) {
            return null;
        }

        $root = $entries[0];
        $dist = $root.DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'dist';
        if (File::isDirectory($dist) && File::exists($dist.DIRECTORY_SEPARATOR.'index.html')) {
            return $dist;
        }

        return null;
    }

    private function overlayDirectory(string $source, string $destination): int
    {
        File::ensureDirectoryExists($destination);
        $written = 0;
        $skip = array_map('strtolower', (array) config('agrivision.updates.skip_zip_segments', []));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            if ($relative === '' || $relative === false) {
                continue;
            }

            $segments = explode('/', strtolower($relative));
            foreach ($segments as $segment) {
                if (in_array($segment, $skip, true)) {
                    continue 2;
                }
            }

            if ($this->isPreservedRelative($relative)) {
                continue;
            }

            $target = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if ($file->isDir()) {
                File::ensureDirectoryExists($target);

                continue;
            }

            File::ensureDirectoryExists(dirname($target));
            File::copy($file->getPathname(), $target);
            $written++;
        }

        return $written;
    }

    private function isPreservedRelative(string $relative): bool
    {
        $relative = strtolower(str_replace('\\', '/', $relative));
        foreach ((array) config('agrivision.updates.preserve', []) as $preserve) {
            $preserve = strtolower(trim(str_replace('\\', '/', (string) $preserve), '/'));
            if ($preserve === '') {
                continue;
            }
            if ($relative === $preserve || str_starts_with($relative, $preserve.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        $path = storage_path('app/'.self::STATE_FILE);
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(array $state): void
    {
        File::put(
            storage_path('app/'.self::STATE_FILE),
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
