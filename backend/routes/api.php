<?php

use App\Http\Controllers\SystemUpdateController;
use Illuminate\Support\Facades\Route;

Route::prefix('system')->group(function () {
    Route::get('/update', [SystemUpdateController::class, 'status']);
    Route::post('/update', [SystemUpdateController::class, 'apply']);
});
