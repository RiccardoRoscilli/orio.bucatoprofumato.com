<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    // Segnalazioni "Segnala un problema" verso il task manager (stesso endpoint di ERP ed emmycasa)
    'task_manager' => [
        'url' => env('TASK_MANAGER_URL', 'https://taskman.modulioscommerce.com'),
        'api_key' => env('TASK_MANAGER_API_KEY', 'teaerp_task_2024_secure_key'),
        'default_developer_id' => env('TASK_MANAGER_DEFAULT_DEVELOPER_ID', 6),
    ],

];
