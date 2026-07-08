<?php

namespace App\Services\Support;

use App\Enums\SupportTicketStatus;
use App\Mail\SupportTicketCreatedMail;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupportTicketService
{
    /**
     * Создать тикет от пользователя.
     *
     * @param array{
     *     subject: string,
     *     body: string,
     *     context?: array<string, mixed>|null,
     * } $data
     * @param array<int, UploadedFile> $attachments
     */
    public function createTicket(User $author, array $data, array $attachments = []): SupportTicket
    {
        return DB::transaction(function () use ($author, $data, $attachments) {
            $context = $data['context'] ?? [];
            // Snapshot ролей: пригодится, если позже у юзера сменят роль
            // и тикет нужно будет понять «кем он был, когда писал».
            $context['roles_snapshot'] = $author->getRoleNames()->all();

            $ticket = SupportTicket::create([
                'user_id' => $author->id,
                'subject' => $this->normalizeSubject($data['subject'] ?? '', $data['body']),
                'body' => trim($data['body']),
                'status' => SupportTicketStatus::Open,
                'context' => $context,
            ]);

            foreach ($attachments as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $this->storeAttachment($ticket, null, $author, $file);
                }
            }

            $this->notifyDeveloperOnCreate($ticket);

            return $ticket->fresh(['attachments']);
        });
    }

    /**
     * Добавить ответ в тред. $author может быть как автор тикета, так и админ.
     *
     * @param array<int, UploadedFile> $attachments
     */
    public function addReply(
        SupportTicket $ticket,
        User $author,
        string $body,
        bool $isInternal = false,
        array $attachments = [],
    ): SupportTicketMessage {
        return DB::transaction(function () use ($ticket, $author, $body, $isInternal, $attachments) {
            $message = SupportTicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $author->id,
                'body' => trim($body),
                'is_internal' => $isInternal,
            ]);

            foreach ($attachments as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $this->storeAttachment($ticket, $message, $author, $file);
                }
            }

            // Первая «настоящая» реакция админа (не internal-заметка) —
            // фиксируем как first_response_at.
            $isAdminReply = $author->hasRole('admin') && ! $isInternal;
            if ($isAdminReply && $ticket->first_response_at === null) {
                $ticket->first_response_at = now();
            }
            // Любой ответ автора возвращает тикет в open, если он был
            // resolved — пользователь не согласен с решением.
            if ($author->id === $ticket->user_id && $ticket->status === SupportTicketStatus::Resolved) {
                $ticket->status = SupportTicketStatus::Open;
            }
            // Ответ админа двигает open → in_progress.
            if ($isAdminReply && $ticket->status === SupportTicketStatus::Open) {
                $ticket->status = SupportTicketStatus::InProgress;
                $ticket->assigned_to_user_id ??= $author->id;
            }
            $ticket->save();

            if (! $isInternal) {
                $this->notifyOnReply($ticket, $message);
            }

            return $message->fresh(['attachments']);
        });
    }

    /**
     * Сменить статус тикета. Если переход в resolved/closed — проставляем
     * соответствующие timestamp'ы.
     */
    public function changeStatus(SupportTicket $ticket, SupportTicketStatus $to, User $actor): SupportTicket
    {
        if ($ticket->status === $to) {
            return $ticket;
        }

        $from = $ticket->status;

        $ticket->status = $to;
        if ($to === SupportTicketStatus::Resolved && $ticket->resolved_at === null) {
            $ticket->resolved_at = now();
        }
        if ($to === SupportTicketStatus::Closed && $ticket->closed_at === null) {
            $ticket->closed_at = now();
            $ticket->resolved_at ??= now();
        }
        if ($to === SupportTicketStatus::InProgress) {
            $ticket->assigned_to_user_id ??= $actor->id;
        }
        $ticket->save();

        // Автору — письмо «обращение решено» с вопросом и ответами админа.
        // Только при ПЕРВОМ переходе в resolved/closed и только если закрыл
        // не сам автор (сам закрыл — знает). Письмо вбирает ВСЕ ещё не
        // отправленные почтой ответы (emailed_at IS NULL) и штампует их —
        // чтобы дайджест-крон не прислал их вторым письмом (кейс тикета #70:
        // 2 ответа + «решено» = 3 почти одинаковых письма подряд).
        $terminal = [SupportTicketStatus::Resolved, SupportTicketStatus::Closed];
        if (in_array($to, $terminal, true)
            && ! in_array($from, $terminal, true)
            && $actor->id !== $ticket->user_id
            && $ticket->user?->email) {
            try {
                $staffAnswers = $ticket->messages()
                    ->where('is_internal', false)
                    ->where('user_id', '!=', $ticket->user_id)
                    ->orderBy('id')
                    ->get();
                $pending = $staffAnswers->whereNull('emailed_at')->values();
                // Всё уже уходило дайджестами — приложим последний ответ,
                // чтобы письмо «решено» не осталось без контекста.
                $answers = $pending->isNotEmpty()
                    ? $pending
                    : collect([$staffAnswers->last()])->filter()->values();

                app(\App\Services\Mail\SystemNotificationMailer::class)->sendMailable(
                    $ticket->user->email,
                    new \App\Mail\SupportTicketResolvedMail($ticket, $answers),
                );
                if ($pending->isNotEmpty()) {
                    SupportTicketMessage::query()
                        ->whereIn('id', $pending->pluck('id'))
                        ->update(['emailed_at' => now()]);
                }
            } catch (\Throwable $e) {
                Log::warning('Support: resolved mail failed (non-fatal)', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $ticket;
    }

    private function storeAttachment(
        SupportTicket $ticket,
        ?SupportTicketMessage $message,
        User $uploader,
        UploadedFile $file,
    ): SupportTicketAttachment {
        $disk = 'local';
        $dir = 'support/' . $ticket->id;
        $name = Str::random(24) . '.' . ($file->getClientOriginalExtension() ?: 'bin');

        // ВАЖНО: размер/mime/имя читаем ДО storeAs().
        //
        // Livewire TemporaryUploadedFile после storeAs() теряет связь с
        // исходным temp-файлом в `livewire-tmp/`, и любой вызов getSize() /
        // getMimeType() лезет в FilesystemAdapter->size() уже несуществующего
        // файла → 500 на `file not found`. Видно в логах prod 2026-05-28
        // (stack #5 SupportTicketService.php:154 → ->getSize()).
        $originalName = mb_substr($file->getClientOriginalName(), 0, 255);
        $mimeType = $file->getClientMimeType() ?: $file->getMimeType();
        $sizeBytes = (int) ($file->getSize() ?: 0);

        $path = $file->storeAs($dir, $name, $disk);

        return SupportTicketAttachment::create([
            'ticket_id' => $ticket->id,
            'message_id' => $message?->id,
            'uploaded_by_user_id' => $uploader->id,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'file_path' => $path,
            'disk' => $disk,
        ]);
    }

    private function normalizeSubject(string $subject, string $body): string
    {
        $subject = trim($subject);
        if ($subject !== '') {
            return mb_substr($subject, 0, 200);
        }
        // Subject не задан — берём первые 80 символов тела.
        $first = trim(mb_substr(strip_tags($body), 0, 80));
        return $first !== '' ? $first : 'Без темы';
    }

    private function notifyDeveloperOnCreate(SupportTicket $ticket): void
    {
        $to = config('support.developer_email');
        if (! $to) {
            Log::warning('Support ticket created but developer_email is not configured', [
                'ticket_id' => $ticket->id,
            ]);
            return;
        }
        try {
            app(\App\Services\Mail\SystemNotificationMailer::class)
                ->sendMailable($to, new SupportTicketCreatedMail($ticket));
        } catch (\Throwable $e) {
            // Письмо — best-effort, тикет уже создан и виден в админке.
            Log::error('Failed to send support ticket created mail', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyOnReply(SupportTicket $ticket, SupportTicketMessage $message): void
    {
        // Если ответил админ — in-app нотификация автору тикета мгновенно
        // (bell-dropdown, badge, жирный в «Моих обращениях»).
        //
        // EMAIL здесь НЕ шлём: письма уходят дайджестом — крон
        // support:email-pending-replies собирает сообщения с
        // emailed_at IS NULL в одно письмо на пачку (тихое окно ~5 мин).
        // Иначе серия ответов подряд = серия почти одинаковых писем
        // (кейс тикета #70: 3 письма за 15 минут).
        $isAuthorReply = $message->user_id === $ticket->user_id;

        if (! $isAuthorReply) {
            try {
                $ticket->user->notify(new \App\Notifications\SupportTicketReplyNotification($ticket, $message));
            } catch (\Throwable $e) {
                Log::warning('SupportTicketReplyNotification dispatch failed (non-fatal)', [
                    'ticket_id' => $ticket->id,
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
