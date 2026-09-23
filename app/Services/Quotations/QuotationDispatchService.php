<?php

namespace App\Services\Quotations;

use App\Models\EmailMessage;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use Illuminate\Support\Facades\Storage;

/**
 * Письмо с коммерческим предложением: PDF по шаблону, черновик в треде заявки
 * и пометка, по которой post-send hook переведёт КП в «отправлено», а заявку —
 * в «КП отправлено».
 *
 * Вынесено из `Livewire\Requests\Quotations\Editor::sendQuotation`, чтобы тем
 * же путём ходила и кнопка авто-КП: клиент должен получать один и тот же
 * документ независимо от того, руками его собрали или автоматом.
 */
class QuotationDispatchService
{
    public function __construct(
        private readonly QuotationPdfService $pdf,
        private readonly EmailDraftService $drafts,
    ) {}

    /**
     * Собрать черновик письма с КП — остаётся отправить.
     *
     * @return array{draft: EmailMessage, path: string, filename: string}
     */
    public function prepareDraft(Quotation $quotation, User $author): array
    {
        $request = $quotation->request;

        $binary = $this->pdf->render($quotation, isolated: true);
        $filename = $this->pdf->filename($quotation);
        $path = sprintf('quotations/%d_v%d.pdf', $quotation->id, $quotation->version);
        Storage::disk('local')->put($path, $binary);

        // Отвечаем в треде клиента, если есть на что отвечать: КП в переписке
        // должно лежать рядом с запросом, а не отдельным письмом.
        $lastInbound = EmailMessage::query()
            ->where('related_request_id', $request->id)
            ->where('direction', \App\Enums\MailDirection::Inbound->value)
            ->where('is_draft', false)
            ->orderByDesc('id')
            ->first();

        $draft = $lastInbound
            ? $this->drafts->createReply($request, $lastInbound, $author, replyAll: false)
            : $this->drafts->createCompose($request, $author);

        $artifacts = is_array($draft->detected_artifacts ?? null) ? $draft->detected_artifacts : [];
        $artifacts[] = [
            'type' => 'quotation_sent',
            'quotation_id' => $quotation->id,
            'transition_to_status' => 'quoted',
            'pdf_path' => $path,
        ];

        $draft->forceFill([
            'body_plain' => $this->body($quotation, $author),
            'detected_artifacts' => $artifacts,
        ])->save();

        $draft->attachments()->create([
            'filename' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($binary),
            'content_id' => null,
            'file_path' => $path,
            'disk' => 'local',
            'is_inline' => false,
        ]);

        return ['draft' => $draft, 'path' => $path, 'filename' => $filename];
    }

    /** Текст письма по шаблону из настроек. */
    public function body(Quotation $quotation, User $author): string
    {
        $request = $quotation->request;

        $template = (string) config(
            'services.quotations.email_body_template',
            "Здравствуйте, {client_name}!\n\nВысылаем коммерческое предложение по запросу {internal_code}.\n"
            ."Итого: {total} ₽ (вкл. НДС).\nСрок действия: {valid_until}.\n\nС уважением,\n{sender_name}"
        );

        return strtr($template, [
            '{client_name}' => $request?->client_name ?: 'коллеги',
            '{internal_code}' => (string) $request?->internal_code,
            '{quotation_code}' => $quotation->internal_code.' v'.$quotation->version,
            '{total}' => number_format((float) $quotation->total, 2, '.', ' '),
            '{valid_until}' => $quotation->valid_until?->format('d.m.Y') ?? '—',
            '{sender_name}' => (string) $author->name,
        ]);
    }
}
