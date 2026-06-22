<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\OAuthYandexController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SupportAttachmentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Yandex 360 OAuth flow для подключения почтовых ящиков (XOAUTH2).
    Route::get('/oauth/yandex/authorize', [OAuthYandexController::class, 'authorize'])
        ->name('oauth.yandex.authorize');
    Route::get('/oauth/yandex/callback', [OAuthYandexController::class, 'callback'])
        ->name('oauth.yandex.callback');

    // Заявки — пул менеджера и карточка. Все 4 роли;
    // фильтрация «своё/всё» — внутри Pool component.
    Route::middleware('role:manager,head_of_sales,director,secretary,admin,procurement')->group(function () {
        Route::get('/dashboard/requests', function () {
            return view('requests.index');
        })->name('requests.index');

        // «Почта выбывших» (РОП/директор) / «Почта» (менеджер) — переписка
        // недоступных менеджеров, НЕ привязанная к заявкам. Назначение
        // ответственного — только привилегированным; разграничение и доступ
        // (manager + privileged) проверяются внутри Livewire-компонента.
        Route::get('/dashboard/mail/absent', function () {
            return view('mail.absent');
        })->name('mail.absent');

        // ВАЖНО: статичные роуты должны быть ОБЪЯВЛЕНЫ ДО `{request}`-биндинга,
        // иначе Laravel матчит `auto-closed` как ID модели → invalid integer
        // → 500. Доступ внутри страницы дополнительно фильтруется в Livewire
        // через canSeeAll/role-check.
        Route::get('/dashboard/requests/auto-closed', function () {
            abort_unless(
                auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'admin', 'secretary']),
                403,
            );
            return view('admin.auto-closed.index');
        })->name('requests.auto-closed');

        Route::get('/dashboard/requests/{request}', function (\App\Models\Request $request) {
            return view('requests.show', ['request' => $request]);
        })->name('requests.show');

        Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])
            ->name('attachments.download');
        Route::get('/attachments/{attachment}/preview', [AttachmentController::class, 'preview'])
            ->name('attachments.preview');
        Route::get('/attachments/cid/{emailMessage}/{contentId}', [AttachmentController::class, 'inline'])
            ->where('contentId', '.*')
            ->name('attachments.inline');

        // Аватарки пользователей (3 варианта: neutral/won/lost). Отдача с
        // приватного диска через контроллер. Доступно всем ролям на чтение.
        Route::get('/avatars/{user}/{variant}', [\App\Http\Controllers\UserAvatarController::class, 'show'])
            ->where('user', '\d+')
            ->where('variant', 'neutral|won|lost')
            ->name('user.avatar');

        // Прокси фото каталога с дисковым кэшем (Phase B / 2026-05-21).
        // Внешний photo_url (mylift.ru) делает 302 → CDN, что даёт
        // ~500-800мс на фото × 20 thumb = 10+ секунд waterfall. Прокси
        // кэширует на диск + ставит браузеру Cache-Control max-age=30days.
        Route::get('/img/catalog/{id}', [\App\Http\Controllers\CatalogPhotoProxyController::class, 'show'])
            ->where('id', '\d+')
            ->name('catalog.photo');

        // КП (наши исходящие) — preview + download PDF. Permission проверяется
        // в контроллере (owner/acting/privileged).
        Route::get('/dashboard/quotations/{quotation}/preview',
            [\App\Http\Controllers\QuotationPdfController::class, 'preview'])
            ->name('quotations.preview');
        Route::get('/dashboard/quotations/{quotation}/download',
            [\App\Http\Controllers\QuotationPdfController::class, 'download'])
            ->name('quotations.download');

        // Standalone-поиск по каталогу (без привязки к заявке) —
        // тот же hybrid pipeline (code + trgm + vector), что в
        // ItemCatalogLinkDialog «Похожие из каталога». Доступен всем
        // авторизованным ролям как справочник.
        Route::get('/dashboard/catalog/search', function () {
            return view('catalog.search');
        })->name('catalog.search');

        // Раздел «Обновления» (changelog) — лента важных изменений системы.
        // Доступен на чтение ВСЕМ ролям без разделения. Публикация — ниже,
        // в privileged-группе (updates.manage/create/edit).
        Route::get('/dashboard/updates', function () {
            return view('updates.index');
        })->name('updates.index');
    });

    // Раздел «Почта» — read-only листинг писем всех ящиков (общих +
    // личных менеджеров). Для head_of_sales / secretary / director / admin.
    // Менеджеры в этот раздел не ходят — у них своя карточка заявки с тред-табом.
    Route::middleware('role:head_of_sales,secretary,director,admin')->group(function () {
        Route::get('/dashboard/mail', function () {
            return view('mail.index');
        })->name('mail.index');

        // Раздел «Аналитика» — метрики по менеджерам (динамика закрытых,
        // Успех/Потеря, время закрытия, детализация обработки заявок).
        Route::get('/dashboard/analytics', function () {
            return view('analytics.index');
        })->name('analytics.index');

        // Отчёт «Топ позиций» — самые продаваемые / отказные позиции каталога.
        Route::get('/dashboard/analytics/positions', function () {
            return view('analytics.positions');
        })->name('analytics.positions');

        // Ретроспектива изменения цен каталога (было → стало, тренд).
        Route::get('/dashboard/analytics/price-changes', function () {
            return view('analytics.price-changes');
        })->name('analytics.price-changes');

        // Foundation Фаза 2: auto-rejection — пересмотр писем, которые AI
        // классифицировал как irrelevant/reclamation/accounting/..., и
        // реоткрытие ошибочно отклонённых как Request. Секретарь отвечает за
        // контроль маршрутизации, поэтому имеет доступ наравне с РОП/директором.
        Route::get('/dashboard/mail-review', function () {
            return view('admin.mail-review.index');
        })->name('mail-review.index');

        // Триаж исходящих счетов, не нашедших заявку (Слой B). Авто-привязка
        // (mail:relink-deferred-outbound) разбирает только то, что линкуется по
        // заголовкам; остальное — сюда, привилегированный привязывает вручную.
        Route::get('/dashboard/invoices/unlinked', function () {
            return view('invoices.unlinked');
        })->name('invoices.unlinked');
    });

    // Phase 4: раздел «Счета». Менеджер видит свои Invoice, привилегированные
    // (РОП / директор / секретарь / админ) — все. Permission-фильтр scope='mine'
    // принудительно установлен для менеджеров внутри Livewire-компонента.
    Route::middleware('role:manager,head_of_sales,secretary,director,admin,procurement')->group(function () {
        Route::get('/dashboard/invoices', function () {
            return view('invoices.index');
        })->name('invoices.index');

        // Раздел «Клиенты» — реестр организаций + контактов (email). Доступен и
        // редактируется всеми ролями (реквизиты/скидки нужны в работе).
        Route::get('/dashboard/clients', function () {
            return view('clients.index');
        })->name('clients.index');

        Route::get('/dashboard/clients/contact/{contact}', function (\App\Models\ClientContact $contact) {
            return view('clients.contact', ['contact' => $contact]);
        })->name('clients.contact');

        Route::get('/dashboard/clients/org/{organization}', function (\App\Models\Organization $organization) {
            return view('clients.show', ['organization' => $organization]);
        })->name('clients.show');

        // Раздел «Поставщики» — запросы расценки поставщикам (SupplierInquiry).
        // Тред, помеченный как наш запрос поставщику; ответы в нём — переписка,
        // не клиентские заявки. Доступ всем ролям (как «Клиенты»).
        Route::get('/dashboard/suppliers', function () {
            return view('suppliers.index');
        })->name('suppliers.index');

        // Карточка поставщика из реестра (Фаза 3.1) — объявлена ДО {inquiry},
        // чтобы статичный сегмент registry не матчился как id запроса.
        Route::get('/dashboard/suppliers/registry/{supplier}', function (\App\Models\Supplier $supplier) {
            return view('suppliers.supplier-edit', ['supplier' => $supplier]);
        })->name('suppliers.registry-edit');

        Route::get('/dashboard/suppliers/{inquiry}', function (\App\Models\SupplierInquiry $inquiry) {
            return view('suppliers.show', ['inquiry' => $inquiry]);
        })->name('suppliers.show');

        // Раздел «Снабжение» (Фаза 4) — топ M-позиций, сдерживающих выдачу КП
        // (до-КП заявки с неактуальной ценой), формирование запросов
        // поставщикам по M-артикулу, контроль обновления цен. Доступ:
        // снабжение + менеджер (часто инициатор) + РОП/директор/админ.
        Route::get('/dashboard/procurement', function () {
            return view('procurement.index');
        })->name('procurement.index');
    });

    // Mail routing rules — управление правилами для РОП и директора.
    Route::middleware('role:head_of_sales,director,admin')->group(function () {
        // Раздел «IQOT» — анализ цен конкурентов: пул позиций (из проигранных КП
        // + ручные из каталога), статусы submission и отчёты по позициям.
        Route::get('/dashboard/iqot', function () {
            return view('iqot.index');
        })->name('iqot.index');

        Route::get('/dashboard/mail-rules', function () {
            return view('admin.mail-rules.index');
        })->name('mail-rules.index');

        Route::get('/dashboard/mail-rules/create', function () {
            return view('admin.mail-rules.edit', ['rule' => new \App\Models\MailRoutingRule()]);
        })->name('mail-rules.create');

        Route::get('/dashboard/mail-rules/{rule}/edit', function (\App\Models\MailRoutingRule $rule) {
            return view('admin.mail-rules.edit', ['rule' => $rule]);
        })->name('mail-rules.edit');

        // Стоп-лист отправителей — Phase 1.X (ручной CRUD + кнопка «Закрыть как спам»
        // на карточке заявки). Письма из стоп-листа не попадают в pipeline создания
        // заявок. Менеджер сам сюда не ходит — это административный экран.
        Route::get('/dashboard/sender-blocklist', function () {
            return view('admin.sender-blocklist.index');
        })->name('sender-blocklist.index');

        // Управление менеджерами (Phase 1.13) — список + create/edit + OAuth-привязка ящиков.
        Route::get('/dashboard/managers', function () {
            return view('admin.managers.index');
        })->name('managers.index');

        Route::get('/dashboard/managers/create', function () {
            return view('admin.managers.edit', ['user' => new \App\Models\User()]);
        })->name('managers.create');

        Route::get('/dashboard/managers/{user}/edit', function (\App\Models\User $user) {
            return view('admin.managers.edit', ['user' => $user]);
        })->name('managers.edit');

        // Phase 2: настройки приложения (catalog matching, тарифы и т.п.).
        // Override поверх config(), редактируется через Livewire-страницу.
        Route::get('/dashboard/settings', function () {
            return view('admin.settings.index');
        })->name('settings.index');

        // Phase 6: автоматические уведомления клиенту — toggle по типам +
        // редактируемые тексты + превью с реальной заявкой.
        Route::get('/dashboard/notifications', function () {
            return view('admin.notifications.index');
        })->name('notifications.index');

        Route::get('/dashboard/notifications/{template}/edit', function (\App\Models\ClientNotificationTemplate $template) {
            return view('admin.notifications.edit', ['template' => $template]);
        })->name('notifications.edit');

        // Стоп-лист авто-уведомлений по e-mail клиента: для адреса не слать
        // выбранные типы уведомлений (проверяется в ClientNotificationService::dispatch).
        Route::get('/dashboard/notification-optouts', function () {
            return view('admin.notification-optouts.index');
        })->name('notification-optouts.index');

        // Управление разделом «Обновления» (changelog) — создание/редактирование/
        // публикация записей. Чтение ленты — всем (updates.index выше).
        Route::get('/dashboard/updates/manage', function () {
            return view('admin.updates.index');
        })->name('updates.manage');

        Route::get('/dashboard/updates/manage/create', function () {
            return view('admin.updates.edit', ['entry' => new \App\Models\ChangelogEntry()]);
        })->name('updates.create');

        Route::get('/dashboard/updates/manage/{entry}/edit', function (\App\Models\ChangelogEntry $entry) {
            return view('admin.updates.edit', ['entry' => $entry]);
        })->name('updates.edit');
    });


    // Общие почтовые ящики (mail@myzip.ru, info@myzip.ru и т.п.) —
    // подключение, OAuth/app-password, активация/деактивация
    // распределения заявок. Доступно ТОЛЬКО админу: критично, что
    // ни директор, ни РОП не могут случайно отключить основную почту.
    Route::middleware('role:admin')->group(function () {
        Route::get('/dashboard/mailboxes', function () {
            return view('admin.mailboxes.index');
        })->name('mailboxes.index');
    });

    // Документация — рукописные гайды по ролям (resources/docs/{section}/*.md).
    // Доступ к разделам фильтруется DocsService по ролям пользователя; admin видит всё.
    Route::prefix('docs')->name('docs.')->group(function () {
        Route::get('/', [DocsController::class, 'index'])->name('index');
        // Preview UI-мокапы из design/ui_kits/crm/ — для iframe-вставок
        // в гайды. Поставлен ДО show, иначе show ловил бы /preview/{x}
        // как section=preview/slug=x.
        Route::get('/preview/{name}', [DocsController::class, 'preview'])
            ->where('name', '[a-z0-9-]+')
            ->name('preview');
        Route::get('/{section}/{slug}', [DocsController::class, 'show'])
            ->where(['section' => '[a-z_-]+', 'slug' => '[a-z0-9_-]+'])
            ->name('show');
    });

    // Связь с создателем — тикет-система. Открыта всем авторизованным:
    // любой может создать тикет, видеть СВОИ обращения и скачивать
    // вложения из своих. Админский инбокс — только role:admin.
    Route::prefix('support')->name('support.')->group(function () {
        Route::get('/my', fn () => view('support.my'))->name('my');

        Route::get('/attachments/{attachment}', [SupportAttachmentController::class, 'download'])
            ->name('attachment.download');

        Route::middleware('role:admin')->group(function () {
            Route::get('/', fn () => view('support.inbox'))->name('inbox');
        });

        // Динамическая страница тикета — после статичных, иначе
        // /my / /attachments / / могут схлопнуться в {ticket}.
        Route::get('/{ticket}', function (\App\Models\SupportTicket $ticket) {
            return view('support.show', ['ticket' => $ticket]);
        })->name('show');
    });
});

require __DIR__.'/auth.php';
