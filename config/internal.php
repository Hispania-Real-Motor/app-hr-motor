<?php

return [
    'google_reviews' => [
        'user' => env('INTERNAL_REVIEWS_USER'),
        'password' => env('INTERNAL_REVIEWS_PASSWORD'),
    ],
    'enreach_events' => [
        'secret' => env('ENREACH_EVENTS_SECRET'),
    ],
    'motorflash_commercial_errors' => [
        'secret' => env('MOTORFLASH_COMMERCIAL_ERRORS_SECRET'),
    ],
];
