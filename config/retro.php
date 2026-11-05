<?php

/*
 * Ретрозагрузка после потери базы (deploy/recovery/RETRO_PLAN.md).
 * Заявки, КП и счета собираются из почты напрямую, без конвейера и GPT.
 */
return [
    // Индекс писем (JSONL по ящикам). Лежит вне базы и переживает её пересоздание.
    'index_path' => env('RETRO_INDEX_PATH', storage_path('app/retro')),

    // С какой даты читаем письма: раньше запуска нужны только для цепочек.
    'scan_since' => env('RETRO_SCAN_SINCE', '1-May-2026'),

    // Сколько байт начала письма читать при разборе и при загрузке текста.
    'peek_bytes' => (int) env('RETRO_PEEK_BYTES', 200000),
    'hydrate_peek_bytes' => (int) env('RETRO_HYDRATE_PEEK_BYTES', 512000),

    // Год и верхняя граница старых номеров: всё, что выше, — ошибка разбора.
    'year' => (int) env('RETRO_YEAR', 2026),
    'max_legacy_number' => (int) env('RETRO_MAX_LEGACY_NUMBER', 25000),

    // День аварии: разбираем только заявки, созданные раньше. Цепочки без номера, начатые
    // с этого дня, к заявкам не присоединяются (решения по переписке после аварии нет).
    'crash_date' => env('RETRO_CRASH_DATE', '2026-11-02'),

    // Без активности дольше этого заявка закрывается как восстановленная. 0 — не закрывать.
    'idle_close_days' => (int) env('RETRO_IDLE_CLOSE_DAYS', 30),

    // Адреса этих доменов — свои, клиентом быть не могут.
    'internal_domains' => ['myzip.ru', 'mylift.ru', 'mzcorp.ru'],

    // У этих доменов совпадение домена не означает одного клиента.
    'free_mail_domains' => [
        'gmail.com', 'yandex.ru', 'ya.ru', 'yandex.com', 'mail.ru', 'bk.ru', 'inbox.ru', 'list.ru',
        'rambler.ru', 'outlook.com', 'hotmail.com', 'icloud.com',
    ],

    // Служебные отправители: не клиенты.
    'service_sender_pattern' => '/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply|notification|notifications|robot|bounce)[^@]*@/i',

    // Получатель такого числа разных заявок, ни разу не получивший наш КП или счёт, — вероятно, поставщик.
    'supplier_min_requests' => (int) env('RETRO_SUPPLIER_MIN_REQUESTS', 5),

    // Папка раскладки info@ → ящик ответственного. JSON в .env: адреса сотрудников в репозиторий не кладём.
    'mz_folder_owners' => (array) json_decode((string) env('RETRO_MZ_FOLDER_OWNERS', '{}'), true),
];
