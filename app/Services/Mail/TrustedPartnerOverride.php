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
            $name = (string) ($p['name'] ?? 'unnamed');

            if ($senderPattern === '' || ! @preg_match($senderPattern, $from)) {
                continue;
            }

            // Поддерживаем оба формата конфига:
            //  - 'marker_pattern' => '/regex/' (legacy, единственный)
            //  - 'marker_patterns' => ['/regex1/', '/regex2/', ...] (массив,
            //     match по логическому OR — достаточно одного совпадения)
            $markers = [];
            if (! empty($p['marker_patterns']) && is_array($p['marker_patterns'])) {
                $markers = $p['marker_patterns'];
            } elseif (! empty($p['marker_pattern'])) {
                $markers = [(string) $p['marker_pattern']];
            }

            // Если маркеры не заданы — match только по sender. Полезно для
            // партнёров, у которых вся почта это бизнес-канал клиентских
            // заказов (нет служебной переписки на том же домене).
            if (empty($markers)) {
                return [
                    'category' => EmailCategory::ClientRequest,
                    'partner' => $name,
                    'matched_by' => 'sender_only',
                ];
            }

            foreach ($markers as $idx => $markerPattern) {
                $markerPattern = (string) $markerPattern;
                if ($markerPattern === '') {
                    continue;
                }
                if (@preg_match($markerPattern, $haystack)) {
                    return [
                        'category' => EmailCategory::ClientRequest,
                        'partner' => $name,
                        'matched_by' => 'marker:' . $idx,
                    ];
                }
            }
        }

        return null;
    }
}
