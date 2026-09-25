<?php

namespace App\Services\Clients;

use App\Enums\OrganizationLinkStatus;
use App\Mail\OrganizationLinkPendingMail;
use App\Models\ClientContact;
use App\Models\EmailMessage;
use App\Models\Organization;
use App\Models\OrganizationLinkRequest;
use App\Models\OutboundQuote;
use App\Models\Quotation;
use App\Models\Request as RequestModel;
use App\Models\User;
use App\Notifications\OrganizationLinkPendingNotification;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Защита от сомнительных привязок контрагента к адресу заказчика.
 *
 * Реестр клиентов наполняется автоматически — из наших КП и счетов. Когда
 * реквизиты УЖЕ ИЗВЕСТНОГО контрагента (у него есть свои адреса) попадают
 * в документ для адреса, к которому он не привязан, это чаще ошибка:
 * менеджер выдал КП не на те реквизиты, письмо лежит в чужой заявке, счёт
 * переслали посреднику. Так ООО «ЛИФТРЕМОНТ» повис на адресе КОМБОЛИФТ
 * СЕРВИС, а ТСН «Весна» — на сотруднице «Метеор Лифт».
 *
 * Такую связь автоматика не создаёт: заводит OrganizationLinkRequest и
 * пишет менеджеру, выдавшему документ, со ссылкой на подтверждение.
 *
 * Без вопросов привязываем:
 *   — нового контрагента или контрагента без адресов (ему не с кем путаться);
 *   — адрес на корпоративном домене, где у контрагента уже есть адреса
 *     (новый сотрудник liftremont.ru у ЛИФТРЕМОНТа), если это не отключено
 *     в services.client_links.trust_same_domain.
 */
class OrganizationLinkGuard
{
    public function __construct(
        private readonly RequestOrganizationResolver $orgResolver,
        private readonly SystemNotificationMailer $mailer,
    ) {}

    /**
     * Попытка привязать контрагента к адресу.
     *
     * @param  array{source?: string, request?: ?RequestModel, outbound_quote?: ?OutboundQuote, quotation?: ?Quotation}  $context
     * @param  bool  $notify  false — завести запись без письма (разовые перепрогоны по истории)
     * @param  bool  $record  false — сомнительную связь не заводить на подтверждение, а молча пропустить
     * @return array{status: 'linked'|'exists'|'pending'|'rejected'|'pinned'|'skipped', requests_linked: int}
     */
    public function link(Organization $org, string $email, array $context = [], bool $notify = true, bool $record = true): array
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $this->isInternal($email)) {
            return ['status' => 'skipped', 'requests_linked' => 0];
        }

        $contact = ClientContact::firstOrCreate(['email' => $email]);

        // Закреплённый адрес другими не обогащаем — см. ClientContact::pinnedOrganization.
        if ($contact->pinned_organization_id !== null && (int) $contact->pinned_organization_id !== (int) $org->id) {
            return ['status' => 'pinned', 'requests_linked' => 0];
        }
        if ($org->contacts()->where('client_contacts.id', $contact->id)->exists()) {
            // Связь есть — новые заявки адреса докидываем к контрагенту, как раньше.
            return ['status' => 'exists', 'requests_linked' => $this->orgResolver->backfillForEmailLink($org, $email)];
        }

        $known = $org->contacts()->pluck('client_contacts.email')
            ->map(fn ($e) => mb_strtolower((string) $e))->values()->all();
        if ($known === [] || $this->sameCorporateDomain($email, $known)) {
            return ['status' => 'linked', 'requests_linked' => $this->attach($org, $contact)];
        }
        if (! $record) {
            // Источник без документа (черновик КП, название из веб-формы):
            // спрашивать менеджера не о чем — просто не привязываем.
            return ['status' => 'skipped', 'requests_linked' => 0];
        }

        $existing = OrganizationLinkRequest::query()
            ->where('organization_id', $org->id)->where('client_contact_id', $contact->id)->first();
        if ($existing !== null) {
            // Решение уже есть (или ждёт): второй документ того же адреса не
            // даёт второго письма, отклонённое не предлагаем заново, а связь,
            // которую подтвердили и потом сняли руками, не восстанавливаем.
            return [
                'status' => $existing->status === OrganizationLinkStatus::Rejected ? 'rejected' : ($existing->isPending() ? 'pending' : 'skipped'),
                'requests_linked' => 0,
            ];
        }

        $quote = $context['outbound_quote'] ?? null;
        $quotation = $context['quotation'] ?? null;
        // Заявку перечитываем целиком: вызывающие грузят её урезанной (id, client_email).
        $requestId = $context['request']?->id ?? $quote?->request_id ?? $quotation?->request_id;
        $request = $requestId ? RequestModel::find($requestId) : null;

        $pending = OrganizationLinkRequest::create([
            'organization_id' => $org->id,
            'client_contact_id' => $contact->id,
            'source' => $context['source'] ?? OrganizationLinkRequest::SOURCE_OUTBOUND_QUOTE,
            'request_id' => $request?->id,
            'outbound_quote_id' => $quote?->id,
            'quotation_id' => $quotation?->id,
            'document_type' => $quote?->document_type?->value ?? ($quotation ? 'quotation' : null),
            'document_number' => $quote?->document_number ?? $quotation?->internal_code,
            'known_emails' => array_slice($known, 0, 10),
            'status' => OrganizationLinkStatus::Pending,
        ]);

        if ($notify) {
            $this->notify($pending, $this->responsibleUser($request, $quote, $quotation));
        }

        Log::info('OrganizationLinkGuard: link held for confirmation', [
            'link_request_id' => $pending->id,
            'organization_id' => $org->id,
            'email' => $email,
            'request_id' => $request?->id,
            'notified' => $notify,
        ]);

        return ['status' => 'pending', 'requests_linked' => 0];
    }

    /** Менеджер подтвердил: связь создаётся, заявки адреса получают контрагента. */
    public function confirm(OrganizationLinkRequest $pending, User $by): int
    {
        return DB::transaction(function () use ($pending, $by) {
            $pending->forceFill([
                'status' => OrganizationLinkStatus::Confirmed,
                'decided_by_user_id' => $by->id,
                'decided_at' => now(),
            ])->save();

            $org = $pending->organization;
            $contact = $pending->contact;
            if (! $org || ! $contact || $org->contacts()->where('client_contacts.id', $contact->id)->exists()) {
                return 0;
            }

            return $this->attach($org, $contact);
        });
    }

    /** Менеджер признал ошибкой: связи нет, повторно не спрашиваем. */
    public function reject(OrganizationLinkRequest $pending, User $by): void
    {
        $pending->forceFill([
            'status' => OrganizationLinkStatus::Rejected,
            'decided_by_user_id' => $by->id,
            'decided_at' => now(),
        ])->save();
    }

    /** Может ли пользователь решать по записи: тот, кому писали, РОП, директор, админ. */
    public function canDecide(OrganizationLinkRequest $pending, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return (int) $pending->notified_user_id === (int) $user->id
            || $user->hasAnyRole(['head_of_sales', 'director', 'admin']);
    }

    private function attach(Organization $org, ClientContact $contact): int
    {
        $org->contacts()->syncWithoutDetaching([$contact->id]);

        // Появилась связь email↔организация — точная привязка ещё не
        // привязанных заявок этого email к organization_id.
        return $this->orgResolver->backfillForEmailLink($org, (string) $contact->email);
    }

    /**
     * Кому писать: кто отправил документ, иначе ответственный по заявке или
     * автор нашего КП, иначе РОП.
     */
    private function responsibleUser(?RequestModel $request, ?OutboundQuote $quote, ?Quotation $quotation): ?User
    {
        $sender = $quote?->email_message_id
            ? mb_strtolower((string) EmailMessage::query()->whereKey($quote->email_message_id)->value('from_email'))
            : '';
        $user = $sender !== ''
            ? User::query()->whereRaw('lower(email) = ?', [$sender])->first()
            : null;

        return $user
            ?? (($quotation?->responsible_user_id ?? $quotation?->created_by_user_id) ? User::find($quotation->responsible_user_id ?? $quotation->created_by_user_id) : null)
            ?? ($request?->assigned_user_id ? User::find($request->assigned_user_id) : null)
            ?? User::role('head_of_sales')->orderBy('id')->first();
    }

    private function notify(OrganizationLinkRequest $pending, ?User $user): void
    {
        if ($user === null) {
            Log::warning('OrganizationLinkGuard: nobody to notify', ['link_request_id' => $pending->id]);

            return;
        }

        $pending->forceFill(['notified_user_id' => $user->id, 'notified_at' => now()])->save();
        $pending->loadMissing(['organization', 'contact', 'request']);

        try {
            $user->notify(OrganizationLinkPendingNotification::from($pending));
        } catch (\Throwable $e) {
            Log::warning('OrganizationLinkGuard: bell notify failed (non-fatal)', [
                'link_request_id' => $pending->id, 'error' => $e->getMessage(),
            ]);
        }

        if (trim((string) $user->email) !== '') {
            try {
                $this->mailer->sendMailable($user->email, new OrganizationLinkPendingMail($pending));
            } catch (\Throwable $e) {
                Log::warning('OrganizationLinkGuard: email failed (non-fatal)', [
                    'link_request_id' => $pending->id, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Адрес на корпоративном домене, где у контрагента уже есть адреса.
     * Публичные домены (mail.ru, gmail.com) ничего не доказывают.
     *
     * @param  array<int, string>  $known
     */
    private function sameCorporateDomain(string $email, array $known): bool
    {
        if (! (bool) config('services.client_links.trust_same_domain', true)) {
            return false;
        }
        $domain = self::domain($email);
        if ($domain === '' || in_array($domain, (array) config('services.mail.free_mail_domains', []), true)) {
            return false;
        }

        return in_array($domain, array_map(fn ($e) => self::domain($e), $known), true);
    }

    private function isInternal(string $email): bool
    {
        $internal = array_map(fn ($d) => mb_strtolower(trim((string) $d)), (array) config('services.mail.internal_domains', []));

        return in_array(self::domain($email), $internal, true);
    }

    private static function domain(string $email): string
    {
        return mb_strtolower((string) substr((string) strrchr($email, '@'), 1));
    }
}
