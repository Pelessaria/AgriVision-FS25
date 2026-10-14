<?php

use Illuminate\Support\Facades\Route;

/*
| AgriVision UI (Vite build) lives in public/ as agrivision.html + /assets/*.
| Laravel API stays under /api; health under /up.
*/

Route::get('/{any?}', function () {
    $spa = public_path('agrivision.html');
    if (is_file($spa)) {
        return response()->file($spa);
    }

    return view('welcome');
})->where('any', '^(?!api(?:/|$)|up(?:/|$)).*');
