<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'webhook' => [
        'token' => env('WEBHOOK_TOKEN'),
    ],

    'postman' => [
        'api_key' => env('POSTMAN_API_KEY'),
        'collection_uid' => env('POSTMAN_COLLECTION_UID'),
    ],

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

    'mock_crm' => [
        'url' => env('MOCK_CRM_URL', 'http://localhost:8000'),
        'mode' => env('MOCK_CRM_MODE', 'success'),
        'deals' => [
            'CRM-DEMO-UPDATE' => [
                'version' => 19,
                'updated_at' => '2026-10-24T12:00:00.000000Z',
                'title' => 'Сделка обновлена из mock CRM',
                'status' => 'IN_PROGRESS',
                'amount' => '24500.00',
            ],
        ],
    ],

];
