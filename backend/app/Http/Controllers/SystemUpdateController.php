<?php

namespace App\Http\Controllers;

use App\Services\Update\GithubUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SystemUpdateController extends Controller
{
    public function status(GithubUpdateService $updates): JsonResponse
    {
        try {
            return response()->json($updates->status());
        } catch (Throwable $e) {
            return response()->json([
                'enabled' => (bool) config('agrivision.updates.enabled'),
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    public function apply(Request $request, GithubUpdateService $updates): JsonResponse
    {
        if (! config('agrivision.updates.enabled')) {
            return response()->json(['ok' => false, 'error' => 'Updates are disabled.'], 403);
        }

        // Realm-admin session auth lands with the installer. Until then, require
        // an explicit confirm flag so the endpoint is never a silent one-click.
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
}
