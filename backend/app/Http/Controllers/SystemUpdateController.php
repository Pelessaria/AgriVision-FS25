<?php

namespace App\Http\Controllers;

use App\Services\Update\GithubUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SystemUpdateController extends Controller
{
    public function status(Request $request, GithubUpdateService $updates): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        try {
            return response()->json($updates->status());
        } catch (Throwable $e) {
            // Soft-fail: keep HTTP 200 so the SPA does not treat this as "no backend".
            return response()->json([
                'enabled' => (bool) config('agrivision.updates.enabled'),
                'installedVersion' => (string) config('agrivision.version'),
                'installedSha' => null,
                'installedAt' => null,
                'repo' => config('agrivision.github.owner').'/'.config('agrivision.github.repo'),
                'branch' => (string) config('agrivision.github.branch'),
                'remoteSha' => null,
                'remoteMessage' => null,
                'remoteHtmlUrl' => null,
                'updateAvailable' => false,
                'checkedAt' => gmdate('c'),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function apply(Request $request, GithubUpdateService $updates): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! config('agrivision.updates.enabled')) {
            return response()->json(['ok' => false, 'error' => 'Updates are disabled.'], 403);
        }

        if (! $request->boolean('confirm')) {
            return response()->json([
                'ok' => false,
                'error' => 'Send confirm=true to apply an update from GitHub.',
            ], 422);
        }

        try {
            $result = $updates->apply($request->string('sha')->toString() ?: null);

            return response()->json($result);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        $role = (string) ($user->global_role ?? 'admin');

        return $role === 'admin' || $role === 'realm_admin';
    }
}
