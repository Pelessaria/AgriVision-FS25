<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SystemUpdateController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/csrf-cookie', function () {
    // Session + ValidateCsrfToken middleware set the XSRF-TOKEN cookie.
    return response()->noContent();
});

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:web')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces', [WorkspaceController::class, 'store']);
    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show']);
    Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update']);
    Route::post('/workspaces/{workspace}/sync', [WorkspaceController::class, 'sync']);
    Route::get('/workspaces/{workspace}/map', [WorkspaceController::class, 'map']);

    Route::get('/system/update', [SystemUpdateController::class, 'status']);
    Route::post('/system/update', [SystemUpdateController::class, 'apply']);
});
