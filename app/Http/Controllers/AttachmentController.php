<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отдаёт файлы вложений писем.
 *
 *   GET /attachments/{attachment}
 *     — обычная скачка по id (Content-Disposition: attachment).
 *
 *   GET /attachments/{attachment}/preview
 *     — то же содержимое, но Content-Disposition: inline (для <img> в треде
 *       и лайтбокса).
 *
 *   GET /attachments/cid/{email_message}/{content_id}
 *     — отдаёт inline-вложение по Content-ID для рендера в HTML body
 *       (карточка заявки заменяет cid:... в src на этот URL).
 *
 * Контроль доступа: менеджер видит вложения только своих писем
 * (через связанную Request); РОП/директор/секретарь — любые.
 */
class AttachmentController extends Controller
{
    public function download(HttpRequest $request, EmailAttachment $attachment): Response
    {
        $this->authorizeAccess($request, $attachment);

        return $this->streamAttachment($attachment, HeaderUtils::DISPOSITION_ATTACHMENT);
    }

    /**
     * Inline-просмотр по id вложения (для <img>-thumbnail и лайтбокса).
     */
    public function preview(HttpRequest $request, EmailAttachment $attachment): Response
    {
        $this->authorizeAccess($request, $attachment);

        return $this->streamAttachment($attachment, HeaderUtils::DISPOSITION_INLINE);
    }

    public function inline(HttpRequest $request, EmailMessage $emailMessage, string $contentId): Response
    {
        $contentId = trim(rawurldecode($contentId), "<> \t");

        $attachment = $emailMessage->attachments()
            ->where('content_id', $contentId)
            ->orWhere('content_id', '<' . $contentId . '>')
            ->first();

        if (! $attachment) {
            abort(404);
        }

        $this->authorizeAccess($request, $attachment);

        return $this->streamAttachment($attachment, HeaderUtils::DISPOSITION_INLINE);
    }

    /**
     * Стримит вложение со storage-диска. Content-Disposition строится через
     * HeaderUtils — корректно экранирует не-ASCII имена файлов (RFC 6266
     * filename* + ASCII fallback). Прежний голый `filename="..."` ломал
     * скачивание для писем с кириллическими именами.
     *
     * filename санитизируется через sanitizeForDisposition() — Symfony
     * makeDisposition() кидает InvalidArgumentException на `/` и `\` в
     * filename (в т.ч. в RFC 5987-варианте), а кривое MIME-декодирование
     * иногда оставляет в filename слэши («Для заказа по д/?-?.4а?/.xlsx»).
     * Без санитизации каждый такой attachment отдавал 500.
     */
    private function streamAttachment(EmailAttachment $attachment, string $disposition): Response
    {
        $disk = Storage::disk($attachment->disk);
        if (! $disk->exists($attachment->file_path)) {
            abort(404);
        }

        $safeName = $this->sanitizeForDisposition((string) $attachment->filename);
        $headers = [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $disposition,
                $safeName,
                $this->asciiFallback($safeName),
            ),
        ];

        // Content-Length из $attachment->size_bytes ставить нельзя: значение
        // фиксировалось при IMAP-сохранении и может не совпадать с размером
        // распакованного файла на диске. Несовпадение → браузер обрезает поток
        // и скачивание «не работает». Пусть Symfony отдаёт chunked.
        if ($disposition === HeaderUtils::DISPOSITION_INLINE) {
            $headers['Cache-Control'] = 'private, max-age=86400';
        }

        return response()->stream(
            function () use ($disk, $attachment) {
                $stream = $disk->readStream($attachment->file_path);
                if ($stream) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            $headers,
        );
    }

    /**
     * ASCII-fallback для legacy-клиентов, не понимающих RFC 5987 filename*.
     * Все не-ASCII символы + слэши заменяем на «_», пустое имя → «attachment».
     */
    private function asciiFallback(string $name): string
    {
        // Слэши тоже сюда — Symfony makeDisposition отвергает их в ASCII
        // fallback, поэтому подменяем до передачи в header.
        $ascii = preg_replace('/[^\x20-\x7e]|[\\/\\\\]/', '_', $name) ?? '';
        $ascii = trim(preg_replace('/_+/', '_', $ascii) ?? '', '_ .');

        return $ascii !== '' ? $ascii : 'attachment';
    }

    /**
     * Санитизирует filename для Content-Disposition:
     *   - убирает `/` и `\` (Symfony makeDisposition кидает
     *     InvalidArgumentException на них)
     *   - убирает CR/LF/NUL (header-injection защита)
     *   - возвращает «attachment.bin» если после чистки пусто
     *
     * Используется ДО передачи в HeaderUtils::makeDisposition.
     * Реальный путь к файлу на storage всё равно идёт по `file_path` колонке
     * и не зависит от filename — кривое имя в БД не ломает stream.
     */
    private function sanitizeForDisposition(string $name): string
    {
        $clean = str_replace(['/', '\\', "\r", "\n", "\0"], '_', $name);
        $clean = trim(preg_replace('/_+/', '_', $clean) ?? '', '_ .');

        return $clean !== '' ? $clean : 'attachment.bin';
    }

    private function authorizeAccess(HttpRequest $request, EmailAttachment $attachment): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $email = $attachment->emailMessage;

        // Phase 1.9 drafts: чужие черновики не отдаём никому, кроме автора.
        // Privileged-роли в драфт чужого менеджера не лезут до явной отправки.
        if ($email?->is_draft) {
            if ($email->draft_author_user_id === $user->id) {
                return;
            }
            abort(403, 'Это черновик другого менеджера.');
        }

        $isPrivileged = $user->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Secretary->value,
        ]);
        if ($isPrivileged) {
            return;
        }

        // Менеджер видит вложения только своих писем (через related Request).
        $relatedRequest = $email?->related_request_id
            ? \App\Models\Request::find($email->related_request_id)
            : null;

        if ($relatedRequest && $relatedRequest->assigned_user_id === $user->id) {
            return;
        }

        abort(403, 'Нет доступа к этому вложению.');
    }

}
