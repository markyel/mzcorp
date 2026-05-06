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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Yandex 360 OAuth (XOAUTH2 для IMAP/SMTP).
    | Регистрируем приложение на https://oauth.yandex.ru.
    | Скоупы для MyLift: mail:imap_full + mail:smtp.
    */
    'yandex' => [
        'client_id' => env('YANDEX_OAUTH_CLIENT_ID'),
        'client_secret' => env('YANDEX_OAUTH_CLIENT_SECRET'),
        'redirect_uri' => env(
            'YANDEX_OAUTH_REDIRECT_URI',
            rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/oauth/yandex/callback'
        ),
        'scope' => env('YANDEX_OAUTH_SCOPE', 'mail:imap_full mail:smtp'),
    ],

];
