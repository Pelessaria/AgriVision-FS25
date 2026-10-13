<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Dedicated server web API (Giants feed)
    |--------------------------------------------------------------------------
    |
    | Used by `php artisan fs25:feed-fetch` when --base/--code/--url are omitted.
    | Never expose FS25_FEED_CODE to the browser or frontend build.
    |
    */
    'feed' => [
        'base' => env('FS25_FEED_BASE'),
        'code' => env('FS25_FEED_CODE'),
    ],
];
