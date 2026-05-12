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

    /*
    | OpenAI (для AI-классификации писем, KB-обработки, эмбеддингов).
    | OPENAI_BASE_URL по умолчанию api.openai.com, но для России может быть
    | reverse-proxy (тогда задаётся вместе с OPENAI_PROXY_KEY).
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'proxy_key' => env('OPENAI_PROXY_KEY', ''),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'mail_classifier_model' => env('OPENAI_MAIL_CLASSIFIER_MODEL', 'gpt-4o-mini'),
        // Парсинг позиций заявки (RequestItemParsingService).
        // parsing_model — текстовый чат, vision_model — image_url-чат с detail:high.
        'parsing_model' => env('OPENAI_PARSING_MODEL', 'gpt-4.1'),
        'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o'),
        // Phase 1.8c: расширенный классификатор (LazyLift drop-in). Сложный
        // промпт с reasoning — gpt-4o, не -mini.
        'category_model' => env('OPENAI_CATEGORY_MODEL', 'gpt-4o'),
        // Phase 1.9: 5-й уровень thread linking (ThreadClarificationAi).
        // Простая задача multi-choice над списком из 2-5 заявок — mini хватает.
        'clarification_model' => env('OPENAI_CLARIFICATION_MODEL', 'gpt-4o-mini'),
    ],

    /*
    | Веб-фетч URL'ов из входящих писем (RequestItemParsingService).
    | См. app/Services/Web/InboundUrlFetcherService.php и таблицу
    | `inbound_url_fetches`.
    */
    'web_fetch' => [
        'enabled' => (bool) env('WEB_FETCH_ENABLED', true),
        'max_urls_per_email' => (int) env('WEB_FETCH_MAX_URLS_PER_EMAIL', 10),
        'url_timeout' => (int) env('WEB_FETCH_URL_TIMEOUT', 10),
        'budget_seconds' => (int) env('WEB_FETCH_BUDGET_SECONDS', 60),
        'max_size_bytes' => (int) env('WEB_FETCH_MAX_SIZE_BYTES', 2 * 1024 * 1024),
        'max_text_chars' => (int) env('WEB_FETCH_MAX_TEXT_CHARS', 8000),
        'cache_ttl_days' => (int) env('WEB_FETCH_CACHE_TTL_DAYS', 7),
        'allowed_content_types' => array_filter(array_map(
            'trim',
            explode(',', (string) env('WEB_FETCH_ALLOWED_CONTENT_TYPES', 'text/html,application/xhtml+xml,text/plain')),
        )),
        'user_agent' => env('WEB_FETCH_USER_AGENT', 'MyLift-Bot/1.0 (+https://mzcorp.ru)'),
    ],

    /*
    | Phase 2: приём snapshot'ов корпоративного каталога (MDB → push API).
    | См. app/Services/Catalog/CatalogImportService.php и
    | app/Http/Controllers/Api/CatalogImportController.php.
    | Пустой токен → endpoint отдаёт 503 (security default).
    */
    'catalog_import' => [
        'token' => env('CATALOG_IMPORT_TOKEN', ''),
        'max_rows' => (int) env('CATALOG_IMPORT_MAX_ROWS', 50000),
        // Серверный гард от обнуления каталога: mode=full snapshot с rows < этого
        // значения отклоняется до записи в БД. Дефолт 1 (только пустой payload
        // запрещён). На рабочем каталоге ставь, скажем, 500 — чтобы битая
        // частичная выгрузка не снесла строки в is_active=false soft-delete'ом.
        'min_full_rows' => (int) env('CATALOG_IMPORT_MIN_FULL_ROWS', 1),
    ],

];
