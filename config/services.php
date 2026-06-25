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
            rtrim((string) env('APP_URL', 'http://localhost'), '/').'/oauth/yandex/callback'
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
        // Парсер исходящих КП/счетов (OutboundQuoteParsingService). Vision+text,
        // сложный длинный промпт со схемой JSON — gpt-4o, не -mini.
        'quote_parser_model' => env('OPENAI_QUOTE_PARSER_MODEL', 'gpt-4o'),
        // Matcher позиций КП → RequestItem fallback (OutboundQuoteItemMatcher).
        // Применяется только на unmatched после детерминированных шагов — mini.
        'quote_matcher_model' => env('OPENAI_QUOTE_MATCHER_MODEL', 'gpt-4o-mini'),
        // Extra-info extractor (AttachmentMetaExtractionService): извлекает
        // из текста вложений серийник лифта, модель, серию, объект, договор,
        // желаемую дату, контактное лицо. Короткий focused-промпт, mini хватает.
        'attachment_meta_model' => env('OPENAI_ATTACHMENT_META_MODEL', 'gpt-4o-mini'),
        // Killswitch если LLM-расходы нежелательны.
        'attachment_meta_enabled' => filter_var(env('OPENAI_ATTACHMENT_META_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Классификатор релевантности тяжёлых вложений (pdf/xls): несёт ли файл
        // товарную номенклатуру или это служебный документ (реквизиты, счёт на
        // оплату, договор). Не-номенклатурные не рубят unified-путь разбора.
        // Короткая yes/no задача — mini. См. RequestItemParsingService.
        'attachment_relevance_model' => env('OPENAI_ATTACHMENT_RELEVANCE_MODEL', 'gpt-4o-mini'),
        'attachment_relevance_enabled' => filter_var(env('OPENAI_ATTACHMENT_RELEVANCE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Финальный LLM-консолидатор позиций split-пути (собирает один набор из
        // выдач разных парсеров одной заявки, склеивая дубли photo-vs-text).
        // Модель — parsing_model. Killswitch — fallback на механический дедуп.
        'consolidation_enabled' => filter_var(env('OPENAI_CONSOLIDATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Phase 2 use-case C: размер батча на /v1/embeddings (OpenAI лимит 2048).
        // 100 — компромисс между latency на запрос и количеством HTTP-вызовов.
        'embedding_batch_size' => (int) env('OPENAI_EMBEDDING_BATCH_SIZE', 100),
        // Phase 2.1 inheritance: финальный валидатор гипотезы «новая Request —
        // продолжение архивной closed_lost». Бинарная задача yes/no — mini.
        'inheritance_check_model' => env('OPENAI_INHERITANCE_CHECK_MODEL', 'gpt-4o-mini'),

        // Circuit-breaker для OpenAI вызовов. При N подряд transient-ошибок
        // (429 insufficient_quota / 503 / timeout) категоризатор отключается
        // на K минут — не жечь лишние списания у прокси-провайдера + bell-
        // нотификация админу с ссылкой на billing. См. App\Services\AI\
        // OpenAiCircuitBreaker.
        'circuit_breaker' => [
            'fail_threshold' => (int) env('OPENAI_CB_FAIL_THRESHOLD', 3),
            'cooldown_minutes' => (int) env('OPENAI_CB_COOLDOWN_MINUTES', 15),
            'notify_cooldown_minutes' => (int) env('OPENAI_CB_NOTIFY_COOLDOWN_MINUTES', 60),
        ],
    ],

    /*
    | Phase 2.1 — наследование заявок от архивных closed_lost.
    */
    'inheritance' => [
        // Минимальная LLM-уверенность для авто-link'а child→parent.
        // Ниже — оставляем как отдельную заявку без наследования.
        'confidence_threshold' => (float) env('INHERITANCE_CONFIDENCE_THRESHOLD', 0.7),
    ],

    /*
    | Hybrid-search в каталог (CatalogEmbeddingService::topNByQueryText).
    | Все ключи опциональные — defaults задаются в коде.
    */
    'catalog' => [
        'search' => [
            // Отсекать vector-only кандидатов ниже порога (false-positives).
            'min_vector_only' => (float) env('CATALOG_SEARCH_MIN_VECTOR_ONLY', 0.50),
            // Cap количества id'шников для backfill cosine: больше = шире
            // покрытие, но медленнее SQL (JOIN с 35K embeddings). 30 — баланс.
            'backfill_cap' => (int) env('CATALOG_SEARCH_BACKFILL_CAP', 30),
            // Cap токенов для trigramTopN: больше токенов в WHERE OR-ветке =
            // больше matching строк (popular слова раздувают bitmap), каждый
            // re-check word_similarity складывается в секунды. На проде 10-15
            // токенов давали t_trgm_ms = 5-7 сек, top-5 — десятки/сотни мс.
            'trgm_token_cap' => (int) env('CATALOG_SEARCH_TRGM_TOKEN_CAP', 5),
        ],
    ],

    /*
    | Парсер исходящих КП/счетов (Foundation §7, расширение DocumentDetector'а).
    | См. app/Services/Quotes/OutboundQuoteParsingService.php + OutboundQuoteItemMatcher.
    */
    'quotes' => [
        // Порог при котором LLM-fallback matcher'а считает позицию сматченной
        // (см. LLM_CONFIDENCE_SCORES в OutboundQuoteItemMatcher). Ниже — игнор.
        'match_score_threshold' => (float) env('QUOTE_MATCH_SCORE_THRESHOLD', 0.6),
        // Максимальный размер вложения для парсинга (PDF/XLSX/DOCX).
        'max_attachment_bytes' => (int) env('QUOTE_MAX_ATTACHMENT_BYTES', 15 * 1024 * 1024),
        // Расширения, на которых триггерится ParseOutboundQuoteJob.
        'parseable_extensions' => ['pdf', 'xlsx', 'xls', 'docx'],
        // Сколько символов текстового слоя PDF отдавать в базовый промпт парсера.
        // Раньше было захардкожено 8000 → длинные сводные КП (24 позиции, ~19500
        // символов) обрезались, и хвостовые строки не доходили до модели
        // (кейс M-2026-4755). Поднято до 24000 (как в retry-промпте); gpt-4o 128k
        // легко вмещает. См. OutboundQuoteParsingService::buildParsingPrompt.
        'parser_text_limit' => (int) env('QUOTE_PARSER_TEXT_LIMIT', 24000),
        // Порог «похоже, распарсены не все позиции»: если Σ строк.total МЕНЬШЕ
        // итога документа сильнее этого %, помечаем КП как неполный (warning
        // likely_missing_rows + error-лог + повторный проход с указанием искать
        // пропущенные строки). Кейс M-2026-4755: спарсено 5 из 24 строк,
        // Σ=312350 при итоге 472668 (−33.9%). См. validateLineTotals/shouldRetry.
        'missing_rows_pct' => (float) env('QUOTE_MISSING_ROWS_PCT', 10),

        /*
        | Self-healing переразбор упавших исходящих КП/счетов
        | (`quotes:reparse-failed`, крон каждые 30 мин). Кейс M-2026-XXXX:
        | 15.06.2026 OpenAI вернул insufficient_quota (429) на серию счетов —
        | OutboundQuote'ы зависли в status=failed без авто-восстановления,
        | счета не попали в /dashboard/invoices. Команда повторно дёргает
        | ParseOutboundQuoteJob, когда квота восстановилась.
        */
        'reparse_failed' => [
            // Максимум quote'ов за один прогон (защита от лавины вызовов).
            'limit' => (int) env('QUOTE_REPARSE_LIMIT', 50),
            // Брать только failed-quote'ы не старше N дней (старые не воскрешаем —
            // их заявки уже могли уйти дальше по воронке).
            'max_age_days' => (int) env('QUOTE_REPARSE_MAX_AGE_DAYS', 14),
            // Не повторять переразбор одного quote чаще, чем раз в N часов —
            // чтобы при длительном простое OpenAI не жечь cap за один час.
            'min_interval_hours' => (int) env('QUOTE_REPARSE_MIN_INTERVAL_HOURS', 2),
            // Cap авто-переразборов на quote: после N безуспешных попыток
            // считаем документ непарсимым (битый PDF и т.п.) и больше не трогаем.
            'max_attempts' => (int) env('QUOTE_REPARSE_MAX_ATTEMPTS', 6),
        ],
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
        | Allowlist для InternalSenderDetector. Адреса в этом списке
        | НЕ считаются «внутренними сотрудниками», даже если их домен
        | в internal_domains. Типовой кейс: order@myzip.ru — технический
        | ящик сайта, который шлёт заявки клиентов через нашу почту;
        | без allowlist domain match банил его как внутреннего.
        |
        | Список через запятую:
        |   MAIL_INTERNAL_SENDER_ALLOWLIST=order@myzip.ru,web@myzip.ru
        */
        'internal_sender_allowlist' => array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_INTERNAL_SENDER_ALLOWLIST', 'order@myzip.ru'))
        )),

        /*
        | Релей-ящики веб-формы сайта. Письма с этих адресов — заявки с сайта:
        | реальный клиент указан в теле (Организация/Контактное лицо/Телефон/
        | E-mail), а не в From. WebFormSubmissionParser извлекает контакты,
        | IncomingMailProcessor пишет их в Request.client_*, а createReply
        | направляет ручные ответы на client_email, а не на сам релей.
        |
        | Список через запятую: MAIL_WEB_FORM_SENDERS=order@myzip.ru,web@myzip.ru
        */
        'web_form_senders' => array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_WEB_FORM_SENDERS', 'order@myzip.ru'))
        )),

        /*
        | Ящики-форвардеры. С них клиентские заявки ПЕРЕСЫЛАЮТСЯ на info@
        | (вручную/автопереслом), поэтому from_email = технический ящик, а
        | реальный отправитель — в блоке пересылки в теле («От: Имя <e-mail>»).
        | ForwardedRequestParser достаёт его, IncomingMailProcessor /
        | EmailToRequestPromoter пишут в Request.client_*, createReply шлёт
        | ответ клиенту, а не на форвардер. Отличие от web_form_senders:
        | произвольное письмо-пересылка, а не фиксированная HTML-форма сайта.
        |
        | Список через запятую: MAIL_FORWARDER_SENDERS=noreply@myzip.ru
        */
        'forwarder_senders' => array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_FORWARDER_SENDERS', 'noreply@myzip.ru'))
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

        /*
        | OutgoingMailLinker L4 time-window (дни). Outbound-письма
        | привязываются по client_email только если открытая Request
        | создана в окне последних N дней. Защищает от прилипания свежих
        | ответов к давно «остывшим» заявкам того же клиента.
        |
        | 0 — отключить time-window (опасно: возвращает старое поведение,
        | при котором фантомные привязки росли каскадом через L1/L2).
        */
        'outbound_link_window_days' => (int) env('MAIL_OUTBOUND_LINK_WINDOW_DAYS', 90),

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
                // Liftway — бизнес-канал клиентских запросов: клиенты пишут
                // нам ЧЕРЕЗ их систему («Прошу счёт M08977 — 1шт»,
                // «Запрос на подтверждение — ЗК-2026-0288» и т.п.).
                // Достаточно ОДНОГО совпадения из списка (OR-match):
                //  - LZ-REQ-NNNN: исторический код Liftway-saas.
                //  - ЗК-YYYY-NNNN: новый формат «заказ» (наблюдается с 2026-05-22).
                //  - M\d{4,}: упоминание нашего внутреннего M-SKU (M08977,
                //    M02016 и т.п.) — клиент указывает позицию из нашего каталога.
                //  - «прошу/просим/выставить … счёт»: явная просьба
                //    клиента на выставление счёта по позициям.
                //
                // Если придёт сервисное письмо от Liftway (нам выставленный
                // счёт за их SaaS-подписку) — маркеров не будет, остаётся
                // обычная LLM-классификация (там вероятнее всего irrelevant).
                'marker_patterns' => [
                    '/\b(LZ-REQ-\d+|ЗК-\d{4}-\d+)\b/u',
                    '/\bM\d{4,}\b/u',
                    '/(?:прошу|просим|выставить)[^.\n]{0,30}счё?т/iu',
                ],
            ],
        ],
    ],

    /*
    | Phase 4: настройки отправки КП (Quotation) клиенту через ComposeForm.
    | См. App\Livewire\Requests\Quotations\Editor::sendQuotation.
    */
    'quotations' => [
        /*
        | Шаблон тела письма при отправке КП. Plain-text. Placeholders:
        |   {client_name}     — Request.client_name (fallback «коллеги»)
        |   {internal_code}   — код заявки M-YYYY-NNNN
        |   {quotation_code}  — Quotation.internal_code + версия (vN)
        |   {total}           — total ₽ с двумя знаками + thousand-separator
        |   {valid_until}     — дата действия КП DD.MM.YYYY
        |   {sender_name}     — имя менеджера-отправителя
        */
        'email_body_template' => env('QUOTATION_EMAIL_BODY_TEMPLATE')
            ?: "Здравствуйте, {client_name}!\n\nВысылаем коммерческое предложение по запросу {internal_code} (КП {quotation_code}).\n\nИтого: {total} ₽ (вкл. НДС).\nСрок действия: {valid_until}.\n\nС уважением,\n{sender_name}",
    ],

    /*
    | Phase 4: настройки счетов.
    | См. App\Services\Invoices\InvoiceService и InvoicesCheckExpiryCommand.
    */
    'invoices' => [
        /*
        | Срок действия счёта по умолчанию (рабочих дней с учётом
        | российского производственного календаря — см. config/russian_calendar.php).
        | Менеджер может изменить в диалоге выставления.
        */
        'default_validity_business_days' => (int) env('INVOICE_DEFAULT_VALIDITY_BUSINESS_DAYS', 5),
    ],

    /*
    | Автоматические уведомления клиенту (Phase 6). Пороги для «оживляющего»
    | письма по падению цены (ClientNotificationType::RevivalOffer). Значения —
    | дефолты для SettingsService (ключи notifications.revival.*), которые
    | admin может переопределить в Настройках без правки кода.
    */
    'notifications' => [
        'revival' => [
            // За сколько дней назад берём проигранные (closed_lost) заявки.
            'period_days' => (int) env('REVIVAL_PERIOD_DAYS', 14),
            // Минимальное падение цены позиции (%), чтобы счесть КП устаревшим.
            'drop_threshold_pct' => (float) env('REVIVAL_DROP_THRESHOLD_PCT', 10),
            // Фраза-подсказка, которую просим написать клиента в ответ.
            'reply_keyword' => env('REVIVAL_REPLY_KEYWORD', 'прислать новое КП'),
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
    | Модуль поставщиков (Фаза 3.5): авто-напоминания молчащим поставщикам по
    | открытым запросам расценки (RFQ). См. SupplierReminderService +
    | `suppliers:remind` cron.
    */
    'suppliers' => [
        'reminder' => [
            'enabled' => (bool) env('SUPPLIER_REMINDER_ENABLED', true),
            // Через сколько дней тишины после RFQ слать первое напоминание.
            'first_after_days' => (int) env('SUPPLIER_REMINDER_FIRST_AFTER_DAYS', 3),
            // Интервал между напоминаниями (дней).
            'interval_days' => (int) env('SUPPLIER_REMINDER_INTERVAL_DAYS', 3),
            // Максимум напоминаний на один запрос.
            'max' => (int) env('SUPPLIER_REMINDER_MAX', 2),
        ],
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

    /*
    | Распределение заявок. См. AssignmentService::pickWeightedLeastLoadedManager.
    | РОП может переопределить через UI «Настройки» — DB-override.
    */
    'assignment' => [
        // Адреса-агрегаторы (не конечный клиент): веб-форма сайта, маркетплейсы.
        // За одним таким From стоят РАЗНЫЕ конечные клиенты, поэтому client-
        // sticky «один клиент → один менеджер» по ним НЕ применяется (иначе все
        // заявки сваливаются одному). Round-robin распределяет их равномерно;
        // catalog/text sticky продолжают работать. Кейс: order@myzip.ru.
        'non_sticky_client_emails' => array_values(array_filter(array_map(
            fn ($e) => mb_strtolower(trim((string) $e)),
            explode(',', (string) env('ASSIGNMENT_NON_STICKY_CLIENT_EMAILS', 'order@myzip.ru')),
        ))),

        // Коэффициент скорости догона для новичков / отстающих по нагрузке.
        // 1.0 = плоская раздача (все одинаково), 2.0 = новичок получает ×2
        // от самого загруженного, 5.0 = ×5. Чем больше — тем быстрее
        // новичок догонит. Формула: coef = 1 + (X-1) × (max-load)/(max-min).
        // Рекомендуемый диапазон 1.5..3.0.
        'newbie_boost' => (float) env('ASSIGNMENT_NEWBIE_BOOST', 2.0),

        // Вес статуса в подсчёте нагрузки для round-robin. Идея: чем дальше
        // заявка по воронке, тем меньше «живой» нагрузки на менеджера она
        // создаёт — основная работа по идентификации уже сделана, остаётся
        // контроль/общение. Иначе продуктивный менеджер, доведший много
        // сделок до КП/счёта, выглядит «перегруженным» и перестаёт получать
        // новые лиды (кейс Якубовича 2026-06-09). Не перечисленный open-статус
        // считается за 1.0. Закрытые/paused в нагрузку не входят вообще.
        // См. AssignmentService::pickWeightedLeastLoadedManager.
        'status_load_weights' => [
            'new' => 1.0,
            'assigned' => 1.0,
            'in_progress' => 1.0,
            'awaiting_client_clarification' => 1.0, // ещё уточняем — идентификация не завершена
            'postponed_until' => 0.5,
            'quoted' => 0.5,                         // КП выдано
            'under_review' => 0.5,                   // клиент согласует КП
            'awaiting_invoice' => 0.5,              // согласовано, ждёт счёт
            'invoiced' => 0.25,                      // счёт выставлен
            'paid' => 0.25,                          // оплачено (почти закрыто)
        ],

        // Гладкое распределение заявок по ПРОПУСКНОЙ СПОСОБНОСТИ менеджера
        // (anti «закрыл всё → получил лавину»). Идея: кормить тех, кто реально
        // быстрее разгребает. Каждому считаем «капасити-вес»:
        //   targetWeight = effCloseRate × quota / (liveLoad + K)
        // где effCloseRate — закрытые заявки (успех+потеря) за period_days
        // (ОСНОВНОЙ сигнал — темп работы за ощутимый период; для новичков с 0
        // закрытий берём base_close_rate), liveLoad — взвешенная текущая
        // нагрузка (демпфер: завалённого сейчас не перегружаем), quota =
        // load_weight/100. Раздача — пропорционально весу через
        // (получено_сегодня / targetWeight) → поток размазывается в течение
        // дня без всплесков, быстрый закрывальщик получает больше плавно.
        // См. AssignmentService::pickBalancedManager.
        'distribution' => [
            // Период подсчёта скорости закрытия (дней).
            'period_days' => (int) env('ASSIGNMENT_PERIOD_DAYS', 14),
            // Базовая скорость закрытия за период для тех, у кого 0 закрытий
            // (новичок / вышел из отпуска) — чтобы получали стартовый поток
            // и могли разогнаться. Масштабируется на quota.
            'base_close_rate' => (float) env('ASSIGNMENT_BASE_CLOSE_RATE', 10),
            // Сглаживающая константа K (демпфер деления при малой текущей
            // нагрузке; меньше K — сильнее приоритет недозагруженным).
            'smoothing_k' => (float) env('ASSIGNMENT_SMOOTHING_K', 30),
            // Микс трёх сигналов в итоговый капасити-вес (нормированные доли):
            //   weight — поровну по load_weight (база/floor, никто не в нуле);
            //   load   — по текущей нагрузке (недозагруженным больше);
            //   speed  — по скорости закрытия за период (быстрым больше).
            // Сумма ≈ 1.0. По ТЗ заказчика 0.2 / 0.4 / 0.4.
            'mix' => [
                'weight' => (float) env('ASSIGNMENT_MIX_WEIGHT', 0.2),
                'load' => (float) env('ASSIGNMENT_MIX_LOAD', 0.4),
                'speed' => (float) env('ASSIGNMENT_MIX_SPEED', 0.4),
            ],
        ],
    ],

    'dealer' => [
        // Порог авто-пометки «дилерского» email. Если у одного client_email
        // открыто столько заявок или больше — он автоматически помечается
        // и client-sticky (1b) для него отключается. Catalog/text-sticky
        // продолжают работать. 0 — выключить автопометку.
        'auto_threshold' => (int) env('DEALER_AUTO_THRESHOLD', 8),
    ],

    'company' => [
        // Реквизиты исполнителя в шапке КП и других исходящих документов.
        // Snapshot пишется в `quotations.snapshot_company` jsonb при отправке
        // — исторические КП immutable даже если потом config поменяется.
        'legal_name' => 'ООО "Мой Лифт"',
        'short_name' => 'Мой ЗиП',
        'inn' => '7715802492',
        'kpp' => '770101001',
        'postal_code' => '105082',
        'address' => 'Город Москва, вн.тер.г. муниципальный округ Басманный, ул. Большая Почтовая, д. № 26В, стр. 1, помещ. 1П',
        'phone' => '+7 (800) 333-64-72',
        'email' => 'info@mylift.ru',
        'edo_id' => '2BM-7715802492-771501001-201508280716279716524',
        'director_name' => 'Боев А. И.',
        'director_title' => 'Генеральный директор',
        // Логотип/бренд-блок справа в шапке PDF.
        'brand_tagline' => 'Запасные части и принадлежности для лифтов и эскалаторов',
        'brand_address' => '127549, Москва, ул. Бибиревская, д.2, кор.1, офис 104',
        'brand_phone' => '+7 (495) 565 37 72',
        'brand_email' => 'info@myZiP.ru',

        // Поля для email-подписи (2026-05-21, EmailSignatureService).
        // Общая часть всех писем менеджеров; персональная часть берётся
        // из User (name, name_en, email, phone_extension, mobile_phone).
        'signature' => [
            'tagline_ru' => 'Мой ЗиП · Запчасти для лифтов и эскалаторов',
            'tagline_en' => 'Spare parts for elevators and escalators',
            'office_phone' => '+7 (495) 565-37-72',
            'free_phone' => '8 (800) 333-64-72',
            'general_email' => 'info@myzip.ru',
            'websites' => ['myzip.ru', 'mylift.ru'],
            // http(s) URL логотипа. Используется двояко:
            //  - как <img src> в HTML-подписи для CRM-превью в браузере;
            //  - его path резолвится в локальный public/-файл, который
            //    OutgoingMailMimeBuilder встраивает inline-вложением (CID).
            // В реальном письме src заменяется на cid:-ссылку — Gmail вырезает
            // data:image base64, а внешние картинки часть клиентов прячет.
            // ТОЛЬКО PNG: SVG почтовые клиенты не рендерят.
            'logo_url' => env('SIGNATURE_LOGO_URL', 'https://mzcorp.ru/assets/logo-myzip-email.png'),
            'brand_color' => '#D32027',
        ],
    ],

    /*
    | IQOT — анализ цен конкурентов (Public API v1, порт интеграции из LazyLift).
    | base_url/timeout — инфраструктура (env). Остальные ключи (enabled, api_key,
    | daily_limit, report_fresh_days, root_category) редактируются в UI «Настройки»
    | (app_settings, ключи iqot.*); значения ниже — fallback-дефолты.
    | См. app/Services/Iqot/IqotApiService.php.
    */
    'iqot' => [
        'base_url' => env('IQOT_BASE_URL', 'https://iqot.ru/api/v1'),
        'timeout' => (int) env('IQOT_TIMEOUT', 30),
        // Поллинг submissions (iqot:poll): троттлим запросы и останавливаем
        // прогон при rate-limit, чтобы не выгребать лимит IQOT залпом и не
        // засорять лог сериями 429. retry_after_cap_s — потолок ожидания по
        // Retry-After внутри HTTP-ретраев.
        'poll' => [
            'inter_request_ms' => (int) env('IQOT_POLL_INTER_REQUEST_MS', 300),
            'max_per_run' => (int) env('IQOT_POLL_MAX_PER_RUN', 100),
            'retry_after_cap_s' => (int) env('IQOT_POLL_RETRY_AFTER_CAP_S', 8),
        ],
        'enabled' => (bool) env('IQOT_ENABLED', false),
        'api_key' => env('IQOT_API_KEY', ''),
        // Дневной лимит позиций на анализ (защита баланса). 0 = не отправлять.
        'daily_limit' => (int) env('IQOT_DAILY_LIMIT', 50),
        // Число заходов крона в день (окно 8–18). daily_limit делится на это
        // число = порция за один заход (чтобы не израсходовать лимит сразу).
        // Должно соответствовать расписанию iqot:dispatch в routes/console.php.
        'runs_per_day' => (int) env('IQOT_RUNS_PER_DAY', 6),
        // Окно актуальности отчёта: позиция со свежим отчётом не пере-анализируется.
        'report_fresh_days' => (int) env('IQOT_REPORT_FRESH_DAYS', 90),
        // Корневая категория (client_category.path[0]) для каждой позиции.
        'root_category' => env('IQOT_ROOT_CATEGORY', 'Лифтовое оборудование'),
        // Приблизительные курсы валют → рубль для сравнения офферов IQOT.
        // Офферы приходят в разных валютах (currency: USD/EUR/CNY/RUB); без
        // конвертации «80 USD» ошибочно сравнивается как «80 ₽». Курс ручной
        // (не онлайн-котировка), редактируется в Настройках (iqot.fx_*).
        // См. app/Services/Iqot/IqotCurrencyConverter.php.
        'fx_rates' => [
            'USD' => (float) env('IQOT_FX_USD', 90),
            'EUR' => (float) env('IQOT_FX_EUR', 100),
            'CNY' => (float) env('IQOT_FX_CNY', 12.5),
        ],
        // Источник дневных курсов для команды iqot:update-fx-rates (ЦБ РФ,
        // без ключа). См. app/Services/Iqot/CbrFxRateProvider.php.
        'fx_source_url' => env('IQOT_FX_SOURCE_URL', 'https://www.cbr.ru/scripts/XML_daily.asp'),
        // Подсветка позиций, требующих пересмотра цены, в списке с готовым
        // отчётом. Флаг ставится, когда наша цена на min_rank-м месте ИЛИ ниже
        // И отклонение от лучшей цены IQOT (без НДС) больше min_deviation_pct %.
        // См. IqotPosition::pricingAttention().
        'attention' => [
            'min_rank' => (int) env('IQOT_ATTENTION_MIN_RANK', 3),
            'min_deviation_pct' => (float) env('IQOT_ATTENTION_MIN_DEVIATION_PCT', 10),
        ],
        // «Кричащий» (критический) алерт — приоритет работы по ценообразованию:
        // наша цена в топ-N% самых дорогих на рынке ПРИ выборке ≥ min_suppliers
        // поставщиков. Такая строка подсвечивается фоном и поднимается наверх.
        // См. IqotPosition::isCriticalPricing().
        'critical' => [
            'top_pct' => (float) env('IQOT_CRITICAL_TOP_PCT', 20),
            'min_suppliers' => (int) env('IQOT_CRITICAL_MIN_SUPPLIERS', 4),
        ],
    ],

    'catalog_sync' => [
        // Public URL до .mdb (mylift.ru/getxfile.php?id=...). Команда
        // catalog:sync-from-url HEAD-проверяет Last-Modified и SHA-256,
        // pull'ит при изменении, конвертит mdb-export → CSV, дёргает
        // catalog:import --apply --encoding=utf-8.
        'url' => env('CATALOG_SYNC_URL'),
        // Имя таблицы в MDB, которую забираем (mdb-export <file> <table>).
        // Узнать у источника или через `mdb-tables file.mdb`.
        'table' => env('CATALOG_SYNC_TABLE', 'Каталог'),
        // Сколько последних снапшотов (mdb+csv) хранить в storage. Старые
        // удаляет ротация после каждого успешного pull'а.
        'keep_snapshots' => (int) env('CATALOG_SYNC_KEEP', 7),
        // SLA для healthcheck: алертим если последний успешный pull был
        // позже чем N часов назад (default 18ч = пропущены оба daily-обновления).
        'alert_stale_hours' => (int) env('CATALOG_SYNC_ALERT_HOURS', 18),
    ],

];
