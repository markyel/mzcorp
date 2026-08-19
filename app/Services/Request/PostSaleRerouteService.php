<?php

namespace App\Services\Request;

use App\Enums\EmailCategory;
use App\Enums\MailDirection;
use App\Enums\Role;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Invoice;
use App\Models\OutboundQuote;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ручное «заворачивание» переписки-фантома в УСПЕШНО закрытую сделку как
 * пост-продажу. Кейс M-2026-11863: ответ клиента в тред закрытого-успехом дела
 * (доставка/документы) выглядел как новая заявка. Реанимировать closed_won
 * нельзя (сделка состоялась), плодить фейк — тоже. Заворачиваем переписку в
 * само выигранное дело: письма → target, входящие помечаются post_sale, target
 * получает post-sale attention (🛒), фантом удаляется. Статус target НЕ меняется.
 *
 * Симметрично авто-пути CheckInheritanceJob::rerouteAsPostSale (крон), но с
 * ручным выбором target и правами. Дополняет автопривязку в InboundReplyLinker
 * (заголовочный тред к closed_won) — на случай, когда авто-связь не сработала
 * (нет заголовка/кода, разные треды).
 */
class PostSaleRerouteService
{
    /** Статусы-цели «успешно закрытой» сделки. */
    private const TARGET_STATUSES = [RequestStatus::ClosedWon, RequestStatus::Paid];

    public function __construct(private readonly AttentionService $attention)
    {
    }

    /**
     * @return array{moved:int, target_code:string, source_code:string}
     *
     * @throws \DomainException
     */
    public function reroute(Request $source, Request $target, User $by): array
    {
        $this->validate($source, $target, $by);

        $sourceCode = $source->internal_code;
        $targetCode = $target->internal_code;
        $moved = 0;

        DB::transaction(function () use ($source, $target, &$moved) {
            $messages = EmailMessage::query()->where('related_request_id', $source->id)->get();
            foreach ($messages as $m) {
                $upd = ['related_request_id' => $target->id];
                // Входящие помечаем post_sale — это пост-продажная переписка,
                // не новый запрос. Исходящие категорию не трогаем.
                if ($m->direction === MailDirection::Inbound) {
                    $upd['category'] = EmailCategory::PostSale->value;
                }
                $m->forceFill($upd)->save();
                $moved++;
            }

            // Напоминания/позиции фантома — не нужны на выигранном деле.
            DB::table('client_notifications_sent')->where('request_id', $source->id)->delete();
            $source->items()->delete();
            $source->delete();
        });

        try {
            $this->attention->onPostSaleMessage($target->fresh());
        } catch (\Throwable $e) {
            Log::warning('PostSaleRerouteService: attention failed (non-fatal)', ['error' => $e->getMessage()]);
        }

        Log::info('PostSaleRerouteService: rerouted correspondence into closed_won deal', [
            'source_request_id' => $source->id,
            'source_code' => $sourceCode,
            'target_request_id' => $target->id,
            'target_code' => $targetCode,
            'moved_messages' => $moved,
            'by_user_id' => $by->id,
        ]);

        return ['moved' => $moved, 'target_code' => $targetCode, 'source_code' => $sourceCode];
    }

    private function validate(Request $source, Request $target, User $by): void
    {
        if ($source->id === $target->id) {
            throw new \DomainException('Нельзя завернуть заявку в саму себя.');
        }
        if (! in_array($target->status, self::TARGET_STATUSES, true)) {
            throw new \DomainException(sprintf(
                'Цель %s должна быть успешно закрытой сделкой (закрыта успехом / оплачена), сейчас — «%s».',
                $target->internal_code,
                $target->status->label(),
            ));
        }
        // Источник — только «фантом»: не выигран сам, без собственных КП/счетов.
        if (in_array($source->status, [RequestStatus::ClosedWon, RequestStatus::Paid], true)
            || OutboundQuote::query()->where('request_id', $source->id)->exists()
            || Invoice::query()->where('request_id', $source->id)->exists()) {
            throw new \DomainException(sprintf(
                'Заявка %s содержит собственные КП/счета или уже выиграна — её нельзя заворачивать как пост-продажу.',
                $source->internal_code,
            ));
        }

        $privileged = $by->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Admin->value]);
        if ($privileged) {
            return;
        }
        if ($by->hasRole(Role::Secretary->value)) {
            throw new \DomainException('Секретарь только просматривает заявки.');
        }
        if (! $source->isAccessibleBy($by) || ! $target->isAccessibleBy($by)) {
            throw new \DomainException('Нет доступа к одной из заявок.');
        }
        $sc = mb_strtolower(trim((string) $source->client_email));
        $tc = mb_strtolower(trim((string) $target->client_email));
        if ($sc !== '' && $tc !== '' && $sc !== $tc) {
            throw new \DomainException('Заворачивать в заявку другого клиента может только РОП, директор или администратор.');
        }
    }
}
