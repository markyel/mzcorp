<?php

namespace App\Services\Mail;

use App\Enums\EmailClassification;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Request\AssignmentService;
use App\Services\Request\InternalCodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Создание Request из inbound email-а с ai_classification=request.
 *
 * Foundation §2.5: «request → создать Request + RequestItem(ы), L1/L2 KB →
 * AssignRequestJob». В Phase 1 урезано:
 *   - RequestItems не создаём (Phase 2);
 *   - L1/L2 KB не используем (Phase 2);
 *   - назначение — round-robin (полный sticky-алгоритм — Phase 2).
 *
 * Идемпотентность: если у EmailMessage уже есть related_request_id —
 * новую заявку не создаём, возвращаем существующую.
 */
class IncomingMailProcessor
{
    public function __construct(
        private readonly InternalCodeGenerator $codeGenerator,
        private readonly AssignmentService $assignment,
        private readonly MailLabelService $labels,
    ) {
    }

    public function processIfRequest(EmailMessage $message): ?Request
    {
        // Только заявки.
        if ($message->ai_classification !== EmailClassification::Request->value) {
            return null;
        }

        // Идемпотентность: уже привязали Request к этому письму.
        if ($message->related_request_id) {
            return Request::find($message->related_request_id);
        }

        $request = DB::transaction(function () use ($message) {
            $req = Request::create([
                'internal_code' => $this->codeGenerator->next(),
                'email_message_id' => $message->id,
                'status' => RequestStatus::New,
                'client_email' => $message->from_email ?: '',
                'client_name' => $message->from_name,
                'subject' => $message->subject,
            ]);

            $message->forceFill(['related_request_id' => $req->id])->save();

            return $req;
        });

        // Round-robin назначение менеджеру.
        $manager = $this->assignment->autoAssign($request);

        // IMAP-метка с именем менеджера. Foundation §1.6: секретарь видит
        // сразу, кому ушла заявка. Yandex 360 ограничивает имя метки 15 символами,
        // поэтому формат «MZ {Lastname}» (3 + до 12 = 15). См. MEMORY.md
        // «Открытые вопросы → 6. Формат IMAP-меток в Yandex 360».
        $label = $manager
            ? 'MZ ' . mb_substr($this->shortName($manager->name), 0, 12)
            : 'MZ нерасп.';
        $this->labels->applyLabel($message, $label);

        Log::info('Request created from incoming mail', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'assigned_to' => $manager?->id,
            'label' => $label,
        ]);

        return $request->fresh();
    }

    /**
     * «Менеджер Иванов Иван» → «Иванов».
     */
    private function shortName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        // Берём слово после «Менеджер» / «РОП» если оно есть, иначе первое.
        if (count($parts) > 1 && in_array(mb_strtolower($parts[0]), ['менеджер', 'роп'], true)) {
            return $parts[1];
        }

        return $parts[0] ?? 'unknown';
    }
}
