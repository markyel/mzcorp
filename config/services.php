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
    /*
    | Foundation §6.2 Phase E.3 — auto-apply enrichment suggestions.
    | Когда LLM-матчер создаёт suggestion с confidence >= порога И у
    | вопроса был target_slot_key (точно знаем куда писать), мы можем
    | сразу применить его без участия менеджера. Порог 0.95 = очень
    | высокая уверенность.
    | Если 0 — auto-apply выключен, все ручные.
    */
    'clarifications' => [
        'auto_apply_threshold' => (float) env('CLARIFICATIONS_AUTO_APPLY_THRESHOLD', 0.95),
        'auto_apply_require_target_slot' => (bool) env('CLARIFICATIONS_AUTO_APPLY_REQUIRE_TARGET', true),
    ],

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
        // Phase 4 outbound LLM-classifier (fallback после rule-based детектора).
        // Простая 4-way классификация — mini достаточно.
        'outbound_classifier_model' => env('OPENAI_OUTBOUND_CLASSIFIER_MODEL', 'gpt-4o-mini'),
        // Phase 2 use-case C: размер батча на /v1/embeddings (OpenAI лимит 2048).
        // 100 — компромисс между latency на запрос и количеством HTTP-вызовов.
        'embedding_batch_size' => (int) env('OPENAI_EMBEDDING_BATCH_SIZE', 100),
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

    /*
    | Phase 2 use-case C: семантический матчинг позиций заявок по name
    | через pgvector-эмбеддинги каталога. См.
    | app/Services/Catalog/CatalogEmbeddingService.php и таблицу
    | `catalog_item_embeddings`.
    |
    | Threshold 0.75 — компромисс «recall vs precision» по cosine similarity.
    | Меньше → больше ложно-положительных, больше → теряем валидные матчи.
    | Будет переехать в UI «Настройки» в отдельном коммите.
    */
    'catalog_name_match' => [
        'enabled' => (bool) env('CATALOG_NAME_MATCH_ENABLED', true),
        // Минимальный cosine similarity для попадания в C-step.
        'threshold' => (float) env('CATALOG_NAME_MATCH_THRESHOLD', 0.75),
        // High-confidence порог: similarity >= hc_threshold → LLM-валидацию
        // не делаем (вектор и так уверен, экономим API-вызовы).
        // Диапазон [threshold, hc_threshold) → обязательная LLM-проверка
        // «один ли это товар» через gpt-4o-mini.
        'hc_threshold' => (float) env('CATALOG_NAME_MATCH_HC_THRESHOLD', 0.90),
        // Killswitch для LLM-валидации (на случай OpenAI rate limit).
        // false → принимаем все vector-match'и выше threshold без LLM.
        'llm_validation_enabled' => (bool) env('CATALOG_NAME_MATCH_LLM_VALIDATION_ENABLED', true),
        // Что делать, если LLM-вызов упал (прокси 503 серией / выход за timeout):
        //   'reject' (default) — match отклоняем, vector без подтверждения LLM
        //                        не доверяем; precision приоритет;
        //   'accept'           — принимаем match без проверки; recall приоритет.
        'llm_fail_action' => env('CATALOG_NAME_MATCH_LLM_FAIL_ACTION', 'reject'),
    ],

    /*
    | Phase 1.9: UI-переписка. Конфиг для исходящих писем из карточки заявки.
    | См. app/Services/Mail/OutgoingMailboxResolver.php и OutgoingMailSender.
    */
    'mail_outbound' => [
        // Shared-ящик для fallback'а, когда у assigned менеджера нет своего
        // OAuth-подключённого personal mailbox'а. Резолвится по точному email'у.
        'shared_email' => env('MAIL_OUTBOUND_SHARED_EMAIL', 'mail@myzip.ru'),
        // Список наших mailbox-адресов в lowercase (для Reply-all фильтра —
        // не отправлять на самих себя). Пусто = берём все Mailbox.email из БД.
        'our_emails' => array_filter(array_map(
            'strtolower',
            array_map('trim', explode(',', (string) env('MAIL_OUTBOUND_OUR_EMAILS', '')))
        )),
        // Лимит attachments на одно письмо (MB). Livewire WithFileUploads
        // ограничивает каждый файл; на агрегат тоже нужен guard.
        'max_attachment_mb' => (int) env('MAIL_OUTBOUND_MAX_ATTACHMENT_MB', 25),
    ],

    /*
    | Внешние маркеры заявок (Phase pool-event +1).
    |
    | Используются InboundReplyLinker::matchByExternalCode (Level 3.5) и
    | аналогом в OutgoingMailLinker. Каждый regex ищется в subject + body_plain
    | входящего/исходящего письма; найденный маркер ведёт к Request через
    | самое раннее EmailMessage с тем же маркером (parent lookup).
    |
    | Решает: (1) дубль-привязку напоминаний партнёрской системы к
    | случайной open Request клиента (Level 4 fallback), (2) дедупликацию
    | копий одного письма, рассылаемого на несколько внутренних адресов.
    */
    'mail' => [
        /*
        | Внутренние домены (наши). Используются InternalSenderDetector
        | в MailCategoryClassifier — если from_email относится к нам,
        | категория принудительно `irrelevant` без LLM-вызова. Кейс
        | M-2026-0161: наш сотрудник прислал короткий комментарий
        | в общий ящик, gpt-4o посчитал его за client_request.
        |
        | Список через запятую: MAIL_INTERNAL_DOMAINS=myzip.ru,mzcorp.ru
        | Дополнительно проверяются совпадения с Mailbox.email и User.email.
        */
        'internal_domains' => array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_INTERNAL_DOMAINS', 'myzip.ru'))
        )),

        /*
        | Empty-content guard в IncomingMailProcessor. Если очищенное тело
        | inbound-письма короче порога И нет attachments — Request не
        | создаётся (письмо переписывается в category=irrelevant). Кейс:
        | «А вложения и не было )» от клиента в reply без header-threading
        | проскакивал как client_request → парсер 0 позиций → пустая
        | заявка назначена менеджеру.
        |
        | 0 = guard выключен.
        */
        'empty_body_guard_min_chars' => (int) env('MAIL_EMPTY_BODY_MIN_CHARS', 40),

        'external_codes' => [
            // Liftway-saas: LZ-REQ-NNNN — общий маркер запроса в их системе.
            '/\bLZ-REQ-\d+\b/u',
        ],

        /*
        | Парсер позиций при reply'ях клиента (Phase 1.9 force-parse).
        |
        | Когда клиент пишет в существующую заявку, MailRouter запускает
        | `ParseRequestItemsJob` с force=true для извлечения дополнительных
        | позиций. Чтобы Vision не плодил ложные дубликаты на «спасибо,
        | фото прилагаю», ReplyParseGate проверяет наличие СИГНАЛОВ позиции
        | в очищенном теле. Если ни один regex не сработал — пропускаем.
        */
        'parser' => [
            // Phase reply-suggestion: пороги для reply-парсинга позиций.
            // confidence = vision_confidence * (1 - fuzzy_penalty_по_артикулу)
            // >= auto → активная позиция; >= suggest → pending; < suggest → skip.
            'reply_auto_apply_threshold' => (float) env('REPLY_AUTO_APPLY_THRESHOLD', 0.95),
            'reply_suggest_threshold' => (float) env('REPLY_SUGGEST_THRESHOLD', 0.70),
            'reply_signals' => [
                // M-SKU (внутренний код каталога).
                '/\bm\d{4,}\b/u',
                // Артикул-подобные «ABC-123», «KM12345», «ZAA-456».
                '/\b[a-z]{2,}-?\d{2,}\b/u',
                // Алфанумерик с цифрой посередине длиной 5+ (типа «KM602345»).
                '/\b[a-z0-9]*\d[a-z0-9-]{3,}[a-z]\b/u',
                // Количество.
                '/\b\d+\s*шт\.?\b/u',
                '/\b\d+\s*штук\b/u',
                // Слова-индикаторы новых позиций.
                '/\b(?:артикул|арт\.?|позиция|позиции|комплект|комплектация|нужн[оы]?|требу[ею]тся|прош[уй]|пришлите|добавьте)\b/u',
            ],
        ],

        /*
        | Trusted partners: партнёрские системы, чьи входящие письма мы
        | хотим принудительно квалифицировать как client_request, минуя
        | LLM-категоризатор. Категоризатор формально прав («это запрос
        | от маркетплейса, не клиент»), но бизнес-логика: каждая такая
        | заявка — полноценный client_request для MyLift (менеджер по
        | ней пишет КП).
        |
        | Match: sender_pattern AND marker_pattern (оба должны совпасть).
        | Action: category=client_request, confidence=1.0, intent=null,
        |         reasoning="Trusted partner override: <name>".
        */
        'trusted_partners' => [
            [
                'name' => 'Liftway-saas',
                'sender_pattern' => '/@(liftway\.store|liftway\.ru)$/i',
                'marker_pattern' => '/\bLZ-REQ-\d+\b/u',
            ],
        ],
    ],

    /*
    | Phase 1.10: state-machine заявок (Foundation §5.4).
    | См. RequestPauseService и `requests:resume-paused` cron.
    */
    'requests' => [
        // Максимальная длительность паузы (дней с сегодняшней даты).
        // Foundation §5.4: «по умолчанию 21 день (3 недели)».
        'max_pause_days' => (int) env('REQUESTS_MAX_PAUSE_DAYS', 21),
    ],

    /*
    | Налоговые ставки. Используются в UI карточки заявки для расчёта
    | итога по каталожным ценам. Меняем через env, если законодательство
    | сдвинет ставку — без правки кода.
    */
    'tax' => [
        // Стандартная ставка НДС в процентах (целое или дробное число).
        // 2019-2026: 20%; 2026+: 22%. Регулируется НК РФ ст. 164.
        'vat_percent' => (float) env('VAT_PERCENT', 22),
    ],

];
