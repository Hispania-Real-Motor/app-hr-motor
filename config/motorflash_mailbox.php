<?php

return [
    'enabled' => (bool) env('MOTORFLASH_MAILBOX_ENABLED', false),
    'allow_non_production' => (bool) env('MOTORFLASH_MAILBOX_ALLOW_NON_PRODUCTION', false),
    'host' => env('MOTORFLASH_MAILBOX_HOST'),
    'port' => (int) env('MOTORFLASH_MAILBOX_PORT', 993),
    'encryption' => env('MOTORFLASH_MAILBOX_ENCRYPTION', 'ssl'),
    'validate_cert' => (bool) env('MOTORFLASH_MAILBOX_VALIDATE_CERT', true),
    'username' => env('MOTORFLASH_MAILBOX_USERNAME'),
    'password' => env('MOTORFLASH_MAILBOX_PASSWORD'),
    'folder' => env('MOTORFLASH_MAILBOX_FOLDER', 'INBOX'),
    'processed_folder' => env('MOTORFLASH_MAILBOX_PROCESSED_FOLDER', 'Procesados'),
    'poll_limit' => (int) env('MOTORFLASH_MAILBOX_POLL_LIMIT', 20),
    'start_uid' => env('MOTORFLASH_MAILBOX_START_UID'),
];
