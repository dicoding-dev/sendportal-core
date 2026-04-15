<?php

return [
    'command_output_path' => env('SENDPORTAL_COMMAND_OUTPUT_PATH'),
    'list_unsubscribe' => [
        'email' => null,
        'url' => null,
    ],
    'stats' => [
        'digest_interval' => env('SENDPORTAL_STATS_DIGEST_INTERVAL', 15),
        'freeze_after_days' => env('SENDPORTAL_STATS_FREEZE_AFTER_DAYS', 45),
    ],
];
