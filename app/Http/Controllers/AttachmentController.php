<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отдаёт файлы вложений писем.
 *
 *   GET /attachments/{attachment}
 *     — обычная скачка по id (Content-Disposition: attachment).
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

        $disk = Storage::disk($attachment->disk);
        if (! $disk->exists($attachment->file_path)) {
            abort(404);
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
            [
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $this->safeFilename($attachment->filename) . '"',
                'Content-Length' => (string) ($attachment->size_bytes ?: ''),
            ],
        );
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

        $disk = Storage::disk($attachment->disk);
        if (! $disk->exists($attachment->file_path)) {
            abort(404);
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
            [
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' . $this->safeFilename($attachment->filename) . '"',
                'Cache-Control' => 'private, max-age=86400',
            ],
        );
    }

    private function authorizeAccess(HttpRequest $request, EmailAttachment $attachment): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
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
        $email = $attachment->emailMessage;
        $relatedRequest = $email?->related_request_id
            ? \App\Models\Request::find($email->related_request_id)
            : null;

        if ($relatedRequest && $relatedRequest->assigned_user_id === $user->id) {
            return;
        }

        abort(403, 'Нет доступа к этому вложению.');
    }

    private function safeFilename(string $name): string
    {
        // Для Content-Disposition экранируем кавычки.
        return str_replace(['"', "\r", "\n"], ['\\"', '', ''], $name);
    }
}
