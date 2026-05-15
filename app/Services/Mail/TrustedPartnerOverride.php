<?php

namespace App\Services\Mail;

use App\Enums\EmailCategory;
use App\Models\EmailMessage;

/**
 * Pre-classifier для партнёрских систем (Liftway-saas и т.п.).
 *
 * Проверяет from_email и наличие маркера в subject/body. Если оба совпали с
 * trusted-partner записью из `config('services.mail.trusted_partners')` —
 * возвращает принудительную категорию (всегда `client_request`).
 *
 * Используется в `MailCategoryClassifier::categorize()` как short-circuit
 * перед LLM-вызовом: для известных партнёров детерминированный override
 * вместо gpt-4o (категоризатор по семантике может пометить как irrelevant —
 * «это запрос от маркетплейса», но бизнес-факт: это client_request).
 */
class TrustedPartnerOverride
{
    /**
     * @return array{category: EmailCategory, partner: string}|null
     */
    public function resolve(EmailMessage $message): ?array
    {
        $partners = (array) config('services.mail.trusted_partners', []);
        if (empty($partners)) {
            return null;
        }

        $from = (string) $message->from_email;
        if ($from === '') {
            return null;
        }
        $haystack = ((string) $message->subject) . "\n" . ((string) $message->body_plain);

        foreach ($partners as $p) {
            $senderPattern = (string) ($p['sender_pattern'] ?? '');
            $markerPattern = (string) ($p['marker_pattern'] ?? '');
            $name = (string) ($p['name'] ?? 'unnamed');

            if ($senderPattern === '' || $markerPattern === '') {
                continue;
            }
            if (! @preg_match($senderPattern, $from)) {
                continue;
            }
            if (! @preg_match($markerPattern, $haystack)) {
                continue;
            }

            return [
                'category' => EmailCategory::ClientRequest,
                'partner' => $name,
            ];
        }

        return null;
    }
}
