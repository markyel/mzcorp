<?php

namespace App\Services\Marketing;

use App\Enums\MailboxType;
use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MarketingBlock;
use App\Models\User;
use App\Services\Mail\EmailSignatureService;
use App\Services\Mail\OutgoingMailMimeBuilder;
use App\Services\Mail\OutgoingMailSender;
use App\Services\Supplier\SupplierRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Рекламный блок под подписью менеджера в исходящих письмах клиентам.
 *
 * Одна точка вставки — OutgoingMailMimeBuilder::composeFinalBody(): через неё
 * идут и ручные ответы менеджеров, и авто-уведомления (ClientNotificationService),
 * и RFQ поставщикам. Поэтому исключения решаются здесь, по получателям:
 *   - письмо поставщику (SupplierRegistry по адресу To, либо уже проставлен
 *     supplier_inquiry_id) — без рекламы;
 *   - все получатели внутренние (services.mail.internal_domains) — без рекламы;
 *   - нет активных блоков — ничего не вставляем.
 *
 * Выбор случайный, но ОДИН на письмо: composeFinalBody вызывается дважды за
 * отправку (сохранить тело в БД + собрать MIME), поэтому выбранный id
 * фиксируется в `detected_artifacts.marketing_block_id` на инстансе драфта и
 * сохраняется вместе с ним в OutgoingMailSender. Там же инкрементируется
 * impressions_count. Тестовая отправка из админки передаёт payload блока прямо
 * в `detected_artifacts.marketing_test` (блок может быть ещё не сохранён) и
 * счётчики не трогает.
 */
class MarketingBlockService
{
    public const ARTIFACT_BLOCK_ID = 'marketing_block_id';

    public const ARTIFACT_TEST = 'marketing_test';

    /** CID inline-картинки блока в MIME (multipart/related). */
    public const IMAGE_CID = 'mylift-promo';

    public function __construct(
        private readonly SupplierRegistry $suppliers,
        private readonly EmailSignatureService $signature,
    ) {
    }

    /**
     * Блок для конкретного драфта: тестовый payload → уже выбранный → новый
     * случайный (с фиксацией на драфте). null — блок не вставляем.
     *
     * @return array{html: string, plain: string, image_url: ?string, image_path: ?string}|null
     */
    public function resolveForDraft(EmailMessage $draft): ?array
    {
        $artifacts = (array) ($draft->detected_artifacts ?? []);

        $test = $artifacts[self::ARTIFACT_TEST] ?? null;
        if (is_array($test)) {
            return $this->render($test);
        }

        if (array_key_exists(self::ARTIFACT_BLOCK_ID, $artifacts)) {
            $id = $artifacts[self::ARTIFACT_BLOCK_ID];
            $block = $id ? MarketingBlock::query()->find($id) : null;

            return $block ? $this->renderBlock($block) : null;
        }

        $block = $this->isEligible($draft)
            ? MarketingBlock::query()->active()->inRandomOrder()->first()
            : null;

        // Фиксируем выбор (в т.ч. «без блока» = null) на инстансе — сохранится
        // при $draft->update() в OutgoingMailSender::sendDraft.
        $artifacts[self::ARTIFACT_BLOCK_ID] = $block?->id;
        $draft->detected_artifacts = $artifacts;

        return $block ? $this->renderBlock($block) : null;
    }

    /** Письмо клиенту (не поставщику, не внутреннее)? */
    public function isEligible(EmailMessage $draft): bool
    {
        if ($draft->supplier_inquiry_id) {
            return false;
        }

        $to = $this->emails((array) ($draft->to_recipients ?? []));
        if ($to === []) {
            return false;
        }
        foreach ($to as $email) {
            if ($this->suppliers->isSupplier($email)) {
                return false;
            }
        }

        $all = array_merge($to, $this->emails((array) ($draft->cc_recipients ?? [])));
        foreach ($all as $email) {
            if (! $this->isInternalAddress($email)) {
                return true;
            }
        }

        return false;
    }

    public function isInternalAddress(string $email): bool
    {
        $domain = mb_strtolower(trim((string) strrchr($email, '@')), 'UTF-8');
        $domain = ltrim($domain, '@');
        if ($domain === '') {
            return false;
        }
        foreach ((array) config('services.mail.internal_domains', []) as $d) {
            if ($domain === mb_strtolower(trim((string) $d))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{html: string, plain: string, image_url: ?string, image_path: ?string}
     */
    public function renderBlock(MarketingBlock $block): array
    {
        return $this->render([
            'title' => $block->title,
            'text' => $block->text,
            'url' => $block->url,
            'image_url' => $block->imageUrl(),
            'image_path' => $block->imageLocalPath(),
        ]);
    }

    /**
     * Email-safe разметка: table-layout + inline-CSS, без внешних стилей.
     * Картинка слева (120px), справа заголовок / текст / «Подробнее →».
     * В реальном письме OutgoingMailMimeBuilder заменит image_url на cid:.
     *
     * @param  array{title?: mixed, text?: mixed, url?: mixed, image_url?: mixed, image_path?: mixed}  $d
     * @return array{html: string, plain: string, image_url: ?string, image_path: ?string}
     */
    public function render(array $d): array
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $title = trim((string) ($d['title'] ?? ''));
        $text = trim((string) ($d['text'] ?? ''));
        $url = trim((string) ($d['url'] ?? ''));
        $imageUrl = trim((string) ($d['image_url'] ?? '')) ?: null;
        $imagePath = trim((string) ($d['image_path'] ?? '')) ?: null;
        $brand = (string) (config('services.company.signature.brand_color') ?? '#D32027');

        if ($title === '' && $text === '') {
            return ['html' => '', 'plain' => '', 'image_url' => null, 'image_path' => null];
        }

        $font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif";
        $imgCell = '';
        if ($imageUrl !== null) {
            $img = '<img src="'.$e($imageUrl).'" width="120" alt="'.$e($title).'" '
                .'style="display:block;width:120px;max-width:120px;height:auto;border:0;border-radius:4px">';
            $imgCell = '<td style="padding:12px 0 12px 12px;vertical-align:top;width:120px">'
                .($url !== '' ? '<a href="'.$e($url).'" style="text-decoration:none">'.$img.'</a>' : $img)
                .'</td>';
        }
        $titleHtml = $url !== ''
            ? '<a href="'.$e($url).'" style="color:#0f1419;text-decoration:none">'.$e($title).'</a>'
            : $e($title);
        $more = $url !== ''
            ? '<div style="margin-top:6px"><a href="'.$e($url).'" style="color:'.$e($brand).';text-decoration:none;font-weight:600">Подробнее &rarr;</a></div>'
            : '';

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
            .'style="margin-top:18px;border-collapse:separate;width:100%;max-width:560px;'
            .'border:1px solid #e5e7eb;border-radius:6px;background:#ffffff">'
            .'<tr>'.$imgCell
            .'<td style="padding:12px 14px;vertical-align:top;'.$font.';font-size:13px;line-height:1.45;color:#0f1419">'
            .($title !== '' ? '<div style="font-weight:600;font-size:13.5px;margin-bottom:4px">'.$titleHtml.'</div>' : '')
            .($text !== '' ? '<div style="color:#475569">'.nl2br($e($text)).'</div>' : '')
            .$more
            .'</td></tr></table>';

        $plainLines = array_filter([$title, $text, $url], fn ($s) => $s !== '');
        $plain = "\n\n".implode("\n", $plainLines);

        return ['html' => $html, 'plain' => $plain, 'image_url' => $imageUrl, 'image_path' => $imagePath];
    }

    /**
     * Полный HTML-документ для превью в админке: образец текста письма +
     * подпись автора + блок. Кладётся в iframe srcdoc.
     *
     * @param  array{title?: mixed, text?: mixed, url?: mixed, image_url?: mixed}  $d
     */
    public function previewDocument(array $d, ?User $author): string
    {
        $sig = $this->signature->render($author);
        $block = $this->render($d);

        return '<!doctype html><html><head><meta charset="utf-8"></head>'
            .'<body style="margin:0;padding:16px;background:#fff;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;font-size:14px;line-height:1.5;color:#0f1419">'
            .'<p>Добрый день!</p>'
            .'<p>Направляем коммерческое предложение по вашему запросу. Срок поставки по позициям в наличии — 1–2 рабочих дня.</p>'
            .$sig['html']
            .$block['html']
            .'</body></html>';
    }

    /**
     * Тестовое письмо с блоком на заданный адрес с выбранного ящика. Подпись —
     * владельца личного ящика, иначе — текущего пользователя. Идёт мимо
     * EmailMessage/очереди, с маркером X-MyLift-System-Notification, чтобы копия
     * из «Отправленных» не линковалась и не плодила заявок. Счётчики не трогаем.
     *
     * @param  array{title: string, text: string, url: string, image_url?: ?string, image_path?: ?string}  $d
     */
    public function sendTest(array $d, Mailbox $mailbox, string $to, User $actor, OutgoingMailMimeBuilder $mime, OutgoingMailSender $sender): void
    {
        $author = ($mailbox->type === MailboxType::Personal && $mailbox->owner) ? $mailbox->owner : $actor;

        $draft = new EmailMessage([
            'mailbox_id' => $mailbox->id,
            'direction' => MailDirection::Outbound->value,
            'is_draft' => true,
            'subject' => 'Тест рекламного блока: '.trim((string) $d['title']),
            'body_plain' => "Добрый день!\n\nЭто тестовое письмо из MyLift: так рекламный блок выглядит под подписью менеджера в письмах клиентам. Отвечать на него не нужно.",
            'to_recipients' => [['email' => $to, 'name' => '']],
            'draft_author_user_id' => $author->id,
            'detected_artifacts' => [self::ARTIFACT_TEST => $d],
        ]);
        $draft->setRelation('mailbox', $mailbox);
        $draft->setRelation('attachments', new EloquentCollection);

        $email = $mime->build($draft, $mailbox);
        $email->getHeaders()->addTextHeader('X-MyLift-System-Notification', '1');

        $sender->sendAdHoc($mailbox, $email);
    }

    /**
     * @param  array<int, array{email?: string}|string>  $recipients
     * @return list<string>
     */
    private function emails(array $recipients): array
    {
        $out = [];
        foreach ($recipients as $r) {
            $email = is_string($r) ? $r : (string) ($r['email'] ?? '');
            $email = mb_strtolower(trim($email));
            if ($email !== '' && str_contains($email, '@')) {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }
}
