<?php

namespace App\Livewire\QuoteParts;

use App\Jobs\QuoteParts\SyncQuotePartsPoolJob;
use App\Models\QuotePartsPoolItem;
use App\Services\Procurement\PriceRefreshCandidates;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsPoolService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Раздел «QuoteParts» (на время тестирования платформы): тот же пул, что «К актуализации»
 * в «Снабжении», с выбором группы позиций для передачи в QuoteParts, и сами переданные
 * позиции со статусом из QuoteParts. Доступ — директорат и админ.
 */
class Index extends Component
{
    public const PER_PAGE = 50;

    #[Url(except: 'candidates')]
    public string $tab = 'candidates';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public int $perPage = self::PER_PAGE;

    /** Сколько позиций пула показано (пул бывает в тысячи позиций — частями). */
    public int $poolShown = 200;

    /** @var array<int|string, bool> catalog_item_id => отмечено (вкладка кандидатов) */
    public array $selected = [];

    /** @var array<int|string, bool> pool item id => отмечено (вкладка «В QuoteParts») */
    public array $selectedPool = [];

    public function mount(): void
    {
        // Раздел открыт всем (23.10.2026); вход в кабинет поставщика — отдельно, директорат и админ.
        abort_unless(auth()->check(), 403);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['candidates', 'pool', 'suppliers', 'offers', 'questions', 'prices', 'groups', 'pilot'], true) ? $tab : 'candidates';
        $this->perPage = self::PER_PAGE;
    }

    public function updatedSearch(): void
    {
        $this->perPage = self::PER_PAGE;
    }

    public function loadMore(): void
    {
        $this->perPage += self::PER_PAGE;
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function candidates(): array
    {
        $rows = app(PriceRefreshCandidates::class)->list();
        $search = trim($this->search);

        if ($search !== '') {
            $rows = array_values(array_filter($rows,
                fn ($r) => mb_stripos($r['sku'].' '.$r['name'].' '.(string) $r['brand'], $search) !== false));
        }

        return $rows;
    }

    /** @return array<int, QuotePartsPoolItem> catalog_item_id => позиция пула */
    #[Computed]
    public function poolByCatalogId(): array
    {
        // Только для показанных кандидатов: весь пул (тысячи позиций) не грузим.
        $cids = array_column(array_slice($this->candidates, 0, $this->perPage), 'cid');

        return QuotePartsPoolItem::query()->inPool()->whereIn('catalog_item_id', $cids)->get()->keyBy('catalog_item_id')->all();
    }

    public function showMorePool(): void
    {
        $this->poolShown += 200;
        unset($this->poolItems);
    }

    #[Computed]
    public function poolItems()
    {
        $search = trim($this->search);

        return QuotePartsPoolItem::query()->inPool()
            ->with('catalogItem:id,sku,name,brand')
            ->when($search !== '', fn ($q) => $q->whereHas('catalogItem', fn ($c) => $c
                ->where('sku', 'ilike', '%'.$search.'%')->orWhere('name', 'ilike', '%'.$search.'%')))
            ->orderByRaw("case status when 'paused' then 0 when 'error' then 1 when 'pending' then 2 else 3 end")
            ->orderBy('sku')
            ->limit($this->poolShown)
            ->get();
    }

    /** @return array<string, int> */
    #[Computed]
    public function poolCounts(): array
    {
        return QuotePartsPoolItem::query()->inPool()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
    }

    /** Свежие (7 дней) действующие предложения поставщиков — счётчик вкладки «Актуализация цен». */
    #[Computed]
    public function freshOffersCount(): int
    {
        return \App\Models\QuotePartsOffer::query()->where('superseded', false)->whereIn('status', ['active', 'expiring'])
            ->where(fn ($q) => $q->where('remote_updated_at', '>=', now()->subDays(7))->orWhere('created_at', '>=', now()->subDays(7)))
            ->count();
    }

    /** Позиции с открытыми вопросами поставщиков — счётчик вкладки «Вопросы поставщиков». */
    #[Computed]
    public function openQuestionsCount(): int
    {
        return app(\App\Livewire\QuoteParts\SupplierQuestions::class)->items()->where('open', '>', 0)->count();
    }

    /** Поставщики, приглашённые в QuoteParts (приглашают из карточки поставщика). */
    #[Computed]
    public function invitedSuppliers()
    {
        return \App\Models\Supplier::query()->whereNotNull('quoteparts_link_id')
            ->orderByRaw("case quoteparts_status when 'terms_discussed' then 0 when 'invited' then 1 when 'active' then 2 else 3 end")
            ->orderByDesc('quoteparts_invited_at')
            ->get(['id', 'name', 'email', 'contact_person', 'language', 'supplier_organization_id', 'quoteparts_link_id', 'quoteparts_status', 'quoteparts_invited_at', 'quoteparts_comment']);
    }

    /**
     * Приглашённые поставщики группами: одна связь QuoteParts (организация) — одна строка со
     * всеми её адресами. Статус и комментарий у связи общие.
     *
     * @return \Illuminate\Support\Collection<int, array{name:string, status:?string, comment:?string, invited_at:mixed, suppliers:\Illuminate\Support\Collection}>
     */
    #[Computed]
    public function invitedGroups()
    {
        $orgs = \App\Models\SupplierOrganization::query()
            ->whereIn('id', $this->invitedSuppliers->pluck('supplier_organization_id')->filter()->unique())->pluck('name', 'id');

        return $this->invitedSuppliers
            ->groupBy(fn ($s) => $s->quoteparts_link_id ?: 'sup-'.$s->id)
            ->map(function ($rows) use ($orgs) {
                $first = $rows->sortBy('quoteparts_invited_at')->first();

                return [
                    'name' => (string) ($orgs[$first->supplier_organization_id] ?? null ?: $first->name ?: $first->email),
                    'status' => $rows->first()->quoteparts_status,
                    'comment' => $rows->pluck('quoteparts_comment')->filter()->first(),
                    'invited_at' => $rows->min('quoteparts_invited_at'),
                    'suppliers' => $rows->sortBy('quoteparts_invited_at')->values(),
                ];
            })
            ->values();
    }

    /**
     * Ссылка для входа в личный кабинет поставщика от имени этого адреса — в буфер обмена
     * (страница копирует её сама). Живёт 2 часа; каждую выдачу QuoteParts пишет в журнал.
     */
    public function accessLink(int $supplierId, QuotePartsClient $client): ?string
    {
        abort_unless(auth()->user()?->hasAnyRole(['director', 'admin']), 403);
        $s = \App\Models\Supplier::query()->whereNotNull('quoteparts_link_id')->findOrFail($supplierId);
        try {
            $r = $client->supplierAccessLink($s->quotepartsKey(), (string) $s->email, auth()->user()->name);
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 300));

            return null;
        }
        \Illuminate\Support\Facades\Log::info('QuoteParts supplier cabinet link copied', ['user_id' => auth()->id(), 'supplier_id' => $s->id, 'email' => $r['email'] ?? $s->email]);

        return (string) $r['url'];
    }

    public function refreshSupplierStatuses(\App\Services\QuoteParts\QuotePartsSupplierService $service): void
    {
        try {
            $n = $service->refreshStatuses();
        } catch (Throwable) {
            $n = 0;
        }
        unset($this->invitedSuppliers);
        $this->dispatch('toast', type: 'success', message: 'Статусы поставщиков обновлены'.($n ? ': '.$n : '').'.');
    }

    /* ----------------------------- Пилот ----------------------------------- */

    #[Computed]
    public function pilotSwitchedOn(): bool
    {
        return \App\Services\QuoteParts\QuotePartsPilot::switchedOn();
    }

    /** Кто может участвовать: те, кто отправляет запросы поставщикам. */
    #[Computed]
    public function pilotCandidates()
    {
        return \App\Models\User::query()
            ->whereNull('archived_at')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['manager', 'head_of_sales', 'procurement', 'director', 'admin']))
            ->orderByDesc('quoteparts_pilot')->orderBy('name')
            ->get(['id', 'name', 'email', 'quoteparts_pilot', 'quoteparts_mirror']);
    }

    /** Куда приходят копии переписки: личный ящик сотрудника, иначе адрес входа. */
    #[Computed]
    public function mirrorAddresses(): array
    {
        $personal = \App\Models\Mailbox::query()
            ->where('type', \App\Enums\MailboxType::Personal->value)->where('is_active', true)
            ->whereIn('owner_user_id', $this->pilotCandidates->pluck('id'))
            ->orderBy('id')->get(['owner_user_id', 'email'])
            ->unique('owner_user_id')->pluck('email', 'owner_user_id');

        return $this->pilotCandidates->mapWithKeys(fn ($u) => [$u->id => (string) ($personal[$u->id] ?? $u->email)])->all();
    }

    /**
     * Дублирование переписки — настройка сотрудника, а не запроса: ответы поставщиков
     * по всем его запросам через QuoteParts приходят ему копией, ответ на копию уходит поставщику.
     */
    public function togglePilotMirror(int $userId): void
    {
        $user = \App\Models\User::findOrFail($userId);
        $user->forceFill(['quoteparts_mirror' => ! $user->quoteparts_mirror])->save();
        unset($this->pilotCandidates);
    }

    /** Поставщики, принявшие приглашение, — запросы через QuoteParts у всех сотрудников. */
    #[Computed]
    public function routingOn(): bool
    {
        return \App\Services\QuoteParts\QuotePartsRouting::enabled();
    }

    #[Computed]
    public function routedSuppliers()
    {
        return \App\Models\Supplier::query()->where('quoteparts_status', 'active')->with('organization:id,name')->orderBy('name')->get(['id', 'name', 'email', 'supplier_organization_id']);
    }

    /** Ящики «Почты», куда приходят сообщения поставщиков из переписки QuoteParts. */
    public const MIRROR_SETTING = 'quoteparts.conversation_mailboxes';

    /** @var list<string> */
    public array $mirrorMailboxes = [];

    public bool $mirrorLoaded = false;

    /** Ящик внесистемных в QuoteParts (orders@): состояние из API, грузится при открытии вкладки. */
    public ?array $offsystemBox = null;

    public bool $offsystemLoaded = false;

    public function loadOffsystem(\App\Services\QuoteParts\QuotePartsClient $client): void
    {
        try {
            $this->offsystemBox = $client->offsystemMailbox();
        } catch (\Throwable $e) {
            $this->offsystemBox = ['connected' => false, 'load_error' => mb_substr($e->getMessage(), 0, 200)];
        }
        $this->offsystemLoaded = true;
    }

    /** Код, который Яндекс показал после входа (приложение mzCorp настроено на verification_code). */
    public string $offsystemCode = '';

    public ?string $offsystemMessage = null;

    /**
     * Код подтверждения Яндекса → токены → в QuoteParts (там проверка входа под ящиком).
     * В mzCorp ящик не заводим и токены не храним.
     */
    public function connectOffsystemCode(\App\Services\Mail\YandexOAuthService $oauth, \App\Services\QuoteParts\QuotePartsClient $client): void
    {
        $code = preg_replace('/\s+/', '', $this->offsystemCode) ?? '';
        $this->offsystemCode = '';
        if ($code === '') {
            $this->offsystemMessage = 'Вставьте код, который показал Яндекс.';

            return;
        }
        $address = (string) config('services.quoteparts.offsystem_mailbox');
        try {
            $tokens = $oauth->exchangeCode($code);
            if (empty($tokens['refresh_token'])) {
                throw new \RuntimeException('Яндекс не выдал refresh-токен.');
            }
            $this->offsystemBox = $client->connectOffsystemMailbox([
                'address' => $address,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in' => max(60, (int) now()->diffInSeconds($tokens['expires_at'])),
            ]);
            $this->offsystemMessage = "Ящик {$address} подключён к QuoteParts через Яндекс.";
            \Illuminate\Support\Facades\Log::info('QuoteParts offsystem mailbox connected', ['address' => $address, 'user_id' => auth()->id()]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('QuoteParts offsystem mailbox connect failed', ['address' => $address, 'error' => $e->getMessage()]);
            $this->offsystemMessage = "Ящик {$address} не подключён: ".mb_substr($e->getMessage(), 0, 300).' Код одноразовый — получите новый.';
        }
    }

    #[Computed]
    public function offsystemSuppliers()
    {
        return \App\Models\Supplier::query()->where('quoteparts_offsystem', true)->with('organization:id,name')->orderBy('name')->get(['id', 'name', 'email', 'supplier_organization_id']);
    }

    #[Computed]
    public function mailboxOptions()
    {
        return \App\Models\Mailbox::query()->where('is_active', true)->with('owner:id,name')
            ->orderByRaw("case when type = 'shared' then 0 else 1 end")->orderBy('email')->get(['id', 'email', 'type', 'owner_user_id']);
    }

    public function loadMirror(): void
    {
        $this->mirrorMailboxes = array_values((array) app_setting(self::MIRROR_SETTING, ['info@myzip.ru']));
        $this->mirrorLoaded = true;
    }

    public function saveMirror(\App\Services\Settings\SettingsService $settings, \App\Services\QuoteParts\QuotePartsClient $client): void
    {
        $valid = $this->mailboxOptions->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $emails = array_values(array_intersect(array_map('mb_strtolower', $this->mirrorMailboxes), $valid));
        try {
            $client->setConversationMirror($emails);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 200));

            return;
        }
        $settings->set(self::MIRROR_SETTING, $emails, \App\Models\AppSetting::TYPE_JSON, auth()->id(), 'QuoteParts: ящики «Почты» для сообщений поставщиков из переписки');
        $this->mirrorMailboxes = $emails;
        $this->dispatch('toast', type: 'success', message: $emails === [] ? 'Сообщения поставщиков в «Почту» больше не приходят.' : 'Сообщения поставщиков будут приходить в: '.implode(', ', $emails).'.');
    }

    public function toggleRouting(\App\Services\Settings\SettingsService $settings): void
    {
        $on = ! (bool) app_setting(\App\Services\QuoteParts\QuotePartsRouting::SETTING, true);
        $settings->set(\App\Services\QuoteParts\QuotePartsRouting::SETTING, $on, \App\Models\AppSetting::TYPE_BOOL, auth()->id(),
            'QuoteParts: поставщикам, принявшим приглашение, запросы через платформу');
        unset($this->routingOn);
        $this->dispatch('toast', type: 'success', message: $on ? 'Принявшим приглашение запросы идут через QuoteParts.' : 'Всем поставщикам — снова письмом.');
    }

    public function togglePilotSwitch(\App\Services\Settings\SettingsService $settings): void
    {
        $on = ! $this->pilotSwitchedOn;
        $settings->set(\App\Services\QuoteParts\QuotePartsPilot::SETTING, $on, \App\Models\AppSetting::TYPE_BOOL, auth()->id(),
            'QuoteParts: канал «Запрос через QuoteParts» для участников пилота');
        unset($this->pilotSwitchedOn);
        $this->dispatch('toast', type: 'success', message: $on ? 'QuoteParts включён для участников пилота.' : 'QuoteParts выключен: все работают как раньше.');
    }

    public function togglePilotUser(int $userId): void
    {
        $user = \App\Models\User::findOrFail($userId);
        $user->forceFill(['quoteparts_pilot' => ! $user->quoteparts_pilot])->save();
        unset($this->pilotCandidates);
    }

    #[Computed]
    public function configured(): bool
    {
        return app(QuotePartsClient::class)->isConfigured();
    }

    public function selectShown(): void
    {
        foreach (array_slice($this->candidates, 0, $this->perPage) as $row) {
            if (! isset($this->poolByCatalogId[$row['cid']])) {
                $this->selected[$row['cid']] = true;
            }
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectedPool = [];
    }

    public function transfer(QuotePartsPoolService $pool): void
    {
        $ids = array_map('intval', array_keys(array_filter($this->selected)));
        if ($ids === []) {
            return;
        }

        $result = $pool->add($ids, auth()->user());
        SyncQuotePartsPoolJob::dispatch();

        $this->selected = [];
        unset($this->poolByCatalogId, $this->poolItems, $this->poolCounts);
        $this->dispatch('toast', type: 'success', message: 'В QuoteParts передаётся позиций: '.$result['added']
            .($result['already'] ? ', уже были в пуле: '.$result['already'] : '').'. Статус обновится через минуту.');
    }

    public function remove(QuotePartsPoolService $pool): void
    {
        $ids = array_map('intval', array_keys(array_filter($this->selectedPool)));
        if ($ids === []) {
            return;
        }

        try {
            $n = $pool->remove($ids);
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 200));

            return;
        }

        $this->selectedPool = [];
        unset($this->poolByCatalogId, $this->poolItems, $this->poolCounts);
        $this->dispatch('toast', type: 'success', message: 'Убрано из QuoteParts: '.$n.'. История там сохраняется.');
    }

    public function refreshStatuses(QuotePartsPoolService $pool): void
    {
        SyncQuotePartsPoolJob::dispatch();
        $n = 0;
        try {
            $n = $pool->refreshPausedStatuses();
        } catch (Throwable) {
        }
        unset($this->poolByCatalogId, $this->poolItems, $this->poolCounts);
        $this->dispatch('toast', type: 'success', message: $n > 0 ? 'Статусы обновлены: '.$n.'.' : 'Статусы актуальны; недошедшие позиции отправятся повторно.');
    }

    public function render()
    {
        return view('livewire.quoteparts.index');
    }
}
