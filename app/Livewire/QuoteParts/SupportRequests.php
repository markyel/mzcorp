<?php

namespace App\Livewire\QuoteParts;

use App\Http\Controllers\QuoteParts\SupportInlineImageController;
use App\Livewire\Concerns\InsertsLetterTemplates;
use App\Services\Mail\HtmlSanitizer;
use App\Services\Mail\OutboundHtmlPostProcessor;
use App\Services\QuoteParts\QuotePartsAnswerTranslator;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsPilot;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Вкладка QuoteParts «Поддержка»: письма на support@quoteparts.net (обращения
 * QuoteParts на тему support). Пишут поставщики и те, кого среди них нет (новый поставщик, человек с
 * другой почты) — у таких нет карточки, только адрес. Ответ уходит письмом с support@ от «QuoteParts
 * Support» тому, кто писал; не по-русски — по-английски (перевод кнопкой, его можно поправить).
 *
 * Ответ пишется тем же богатым редактором, что в разделе «Почта» (x-mail.rich-editor, шаблоны писем,
 * адресная книга): копии, скрытые копии, картинки в тексте, вложения. Картинки до отправки лежат у нас
 * (SupportInlineImageController), при отправке уходят в QuoteParts inline-вложениями по cid:.
 */
class SupportRequests extends Component
{
    use InsertsLetterTemplates;
    use WithFileUploads;

    /** Счётчик вкладки «Поддержка» (Index::supportWaitingCount); обновляется при каждой загрузке списка. */
    public const WAITING_CACHE = 'quoteparts:support:waiting';

    /** Причины закрытия (Conversation::RESOLUTIONS в QuoteParts) → подпись плашки. */
    public const RESOLUTIONS = ['resolved' => 'решено', 'no_action' => 'не требует внимания'];

    /** Картинка в тексте ответа: наш адрес до отправки → cid: в письме (имя файла и есть cid). */
    private const INLINE_SRC = '#(src)\s*=\s*(["\'])[^"\']*?/dashboard/quoteparts/support/images/([A-Za-z0-9]{16})/(qpi-[a-z0-9]{12}\.(?:png|jpg|gif))\2#i';

    public bool $loaded = false;

    /** @var list<array<string, mixed>> */
    public array $conversations = [];

    public ?string $error = null;

    /** Показывать и закрытые. */
    public bool $withClosed = false;

    public ?int $activeId = null;

    /** Ответ из редактора — HTML письма. */
    public string $bodyHtml = '';

    /** Его plain-вариант (проверка «пусто», текст письма и переписки в QuoteParts). */
    public string $bodyText = '';

    /** Копия / скрытая копия: «Имя <email>, …», как в композере почты. */
    public string $ccRaw = '';

    public string $bccRaw = '';

    /** Черновик ответа: папка картинок в тексте и wire:key редактора — новый на каждое раскрытое письмо и после отправки. */
    public string $draftKey = '';

    /** @var array<int, mixed> вложения ответа (набираются из нескольких выборов и перетаскиваний) */
    public array $files = [];

    /** @var array<int, mixed> только что выбранные — дописываются в $files */
    public array $newFiles = [];

    public function load(QuotePartsClient $client): void
    {
        $this->error = null;
        try {
            $this->conversations = $client->supportConversations();
            Cache::put(self::WAITING_CACHE, collect($this->conversations)->filter(fn ($c) => self::waiting($c))->count(), 120);
        } catch (Throwable $e) {
            $this->error = 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 200);
        }
        $this->loaded = true;
    }

    public function open(int $id): void
    {
        $this->activeId = $this->activeId === $id ? null : $id;
        $this->freshDraft();
    }

    public function updatedBodyHtml(HtmlSanitizer $sanitizer): void
    {
        $this->bodyHtml = $sanitizer->sanitize($this->bodyHtml);
        $this->bodyText = $this->htmlToPlain($this->bodyHtml);
    }

    public function updatedNewFiles(): void
    {
        $this->validate(['newFiles.*' => ['file', 'max:20480']], [], ['newFiles.*' => 'файл']);
        $this->files = array_values(array_merge($this->files, array_filter($this->newFiles)));
        $this->newFiles = [];
        $this->dispatch('qps-files-added');
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    /** Перевод ответа на английский прямо в редакторе: разметка остаётся, текст можно поправить. */
    public function translate(QuotePartsAnswerTranslator $translator, HtmlSanitizer $sanitizer): void
    {
        $this->bodyHtml = $sanitizer->sanitize($this->bodyHtml);
        if (trim($this->htmlToPlain($this->bodyHtml)) === '') {
            return;
        }
        $en = $translator->toEnglishHtml($this->bodyHtml);
        if ($en === null) {
            $this->dispatch('toast', type: 'error', message: 'Перевод не получился — переведите вручную или попробуйте ещё раз.');

            return;
        }
        $this->bodyHtml = $sanitizer->sanitize($en);
        $this->bodyText = $this->htmlToPlain($this->bodyHtml);
        $this->dispatch('mail-editor-set-html', html: $this->bodyHtml);
    }

    public function reply(QuotePartsClient $client, HtmlSanitizer $sanitizer, OutboundHtmlPostProcessor $post): void
    {
        abort_unless(QuotePartsPilot::canAnswer(auth()->user()), 403);
        $this->validate([
            'ccRaw' => ['nullable', 'string', 'max:4000'],
            'bccRaw' => ['nullable', 'string', 'max:4000'],
            'files.*' => ['file', 'max:20480'],
        ], [], ['ccRaw' => 'копия', 'bccRaw' => 'скрытая копия']);

        $this->bodyHtml = $sanitizer->sanitize($this->bodyHtml);
        $this->bodyText = $this->htmlToPlain($this->bodyHtml);
        if ($this->bodyText === '') {
            $this->addError('bodyText', 'Напишите ответ.');

            return;
        }
        // Писали не по-русски — по-русски не отвечаем: сначала перевод (его можно поправить).
        if ($this->english($this->active()) && preg_match('/\p{Cyrillic}/u', $this->bodyText)) {
            $this->addError('bodyText', 'Писали не по-русски — переведите ответ кнопкой «🌐 Перевести на английский» и проверьте перевод.');

            return;
        }
        [$cc, $badCc] = self::addresses($this->ccRaw);
        [$bcc, $badBcc] = self::addresses($this->bccRaw);
        if ($badCc !== [] || $badBcc !== []) {
            $this->addError($badCc !== [] ? 'ccRaw' : 'bccRaw', 'Не похоже на адрес: '.implode(', ', array_merge($badCc, $badBcc)));

            return;
        }

        [$html, $inline] = $this->cidify($this->bodyHtml);
        try {
            $client->sendConversationMessage((int) $this->activeId, $this->bodyText, auth()->user()->name, $this->uploads(), [
                'html' => $post->emailSafe($html),
                'cc' => $cc,
                'bcc' => $bcc,
                'inline' => $inline,
            ]);
        } catch (Throwable $e) {
            $this->addError('bodyText', mb_substr($e->getMessage(), 0, 300));

            return;
        }
        $this->load($client);
        $this->freshDraft();
        $this->dispatch('toast', type: 'success', message: 'Ответ отправлен с support@quoteparts.net.');
    }

    /** Отметка из строки списка: «Решено» / «Не требует внимания» — письмо уходит из счётчика; новое письмо в обращении вернёт его. */
    public function close(int $id, string $resolution, QuotePartsClient $client): void
    {
        abort_unless(QuotePartsPilot::canAnswer(auth()->user()), 403);
        abort_unless(isset(self::RESOLUTIONS[$resolution]), 422);
        try {
            $client->closeConversation($id, $resolution, auth()->user()->name);
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 200));
        }
        if ($this->activeId === $id) {
            $this->activeId = null;
        }
        $this->load($client);
    }

    public function reopen(int $id, QuotePartsClient $client): void
    {
        abort_unless(QuotePartsPilot::canAnswer(auth()->user()), 403);
        try {
            $client->reopenConversation($id);
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 200));
        }
        $this->load($client);
    }

    /**
     * Отвечать по-английски — по языку письма: написали по-русски — отвечаем по-русски, даже если в
     * карточке поставщика английский (в поддержку пишет не обязательно тот, кто ведёт запросы). Текста нет —
     * по языку карточки поставщика, а у того, кого среди поставщиков нет, — по-русски.
     *
     * @param  array<string, mixed>|null  $c
     */
    public function english(?array $c): bool
    {
        if ($c === null) {
            return false;
        }
        $theirs = trim(collect($c['messages'] ?? [])->where('direction', 'in')->pluck('text')->implode(' '));
        if ($theirs !== '') {
            return ! preg_match('/\p{Cyrillic}/u', $theirs);
        }
        if (! empty($c['supplier'])) {
            return (app(QuotePartsRfqService::class)->supplierLanguages([$c['supplier']])[$c['supplier']] ?? 'ru') === 'en';
        }

        return false;
    }

    /** Ждёт ответа: открыто, последнее сообщение — от того, кто писал. */
    public static function waiting(array $c): bool
    {
        return $c['status'] === 'open' && (collect($c['messages'] ?? [])->last()['direction'] ?? null) === 'in';
    }

    /**
     * «Имя <email>, foo@bar» → [валидные адреса, то, что адресом не оказалось].
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function addresses(string $raw): array
    {
        $ok = $bad = [];
        foreach (preg_split('/[,;\n]+/u', trim($raw)) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $email = preg_match('/<([^>]+)>\s*$/u', $item, $m) ? trim($m[1]) : $item;
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $ok[] = mb_strtolower($email);
            } else {
                $bad[] = $item;
            }
        }

        return [array_values(array_unique($ok)), $bad];
    }

    /**
     * Картинки этого черновика: адрес → cid: (имя файла), сами файлы — inline-вложениями.
     *
     * @return array{0: string, 1: list<array{path:string, name:string}>}
     */
    private function cidify(string $html): array
    {
        $dir = SupportInlineImageController::dir((int) auth()->id(), $this->draftKey);
        $inline = [];
        $out = preg_replace_callback(self::INLINE_SRC, function (array $m) use ($dir, &$inline): string {
            $path = $dir.'/'.$m[4];
            if ($m[3] !== $this->draftKey || ! Storage::disk('local')->exists($path)) {
                return $m[0];
            }
            $inline[$m[4]] = ['path' => Storage::disk('local')->path($path), 'name' => $m[4]];

            return $m[1].'='.$m[2].'cid:'.$m[4].$m[2];
        }, $html);

        return [$out ?? $html, array_values($inline)];
    }

    /**
     * Чистый черновик: картинки прошлого (отправленного или брошенного) — с диска. Копия — как в прошлом
     * нашем ответе в этом письме: обычно отвечают тем же людям.
     */
    private function freshDraft(): void
    {
        if ($this->draftKey !== '') {
            Storage::disk('local')->deleteDirectory(SupportInlineImageController::dir((int) auth()->id(), $this->draftKey));
        }
        $this->reset('bodyHtml', 'bodyText', 'ccRaw', 'bccRaw', 'files', 'newFiles');
        $this->resetErrorBag();
        $this->draftKey = Str::random(16);
        $last = collect($this->active()['messages'] ?? [])->where('direction', 'out')->filter(fn ($m) => ! empty($m['cc']))->last();
        $this->ccRaw = implode(', ', (array) ($last['cc'] ?? []));
    }

    /** @return array<string, mixed>|null */
    private function active(): ?array
    {
        return collect($this->conversations)->firstWhere('id', $this->activeId);
    }

    /** @return list<array{path:string, name:string}> */
    private function uploads(): array
    {
        return collect($this->files)->filter()->map(fn ($f) => ['path' => $f->getRealPath(), 'name' => $f->getClientOriginalName()])->values()->all();
    }

    public function render()
    {
        $rows = collect($this->conversations)
            ->when(! $this->withClosed, fn ($c) => $c->where('status', 'open'))
            ->sortBy(fn ($c) => [self::waiting($c) ? 0 : 1, -strtotime((string) ($c['last_message_at'] ?? 'now'))])
            ->values();

        return view('livewire.quoteparts.support-requests', [
            'rows' => $rows,
            'names' => app(QuotePartsRfqService::class)->namesByKeys(collect($this->conversations)->pluck('supplier')),
            'waitingCount' => collect($this->conversations)->filter(fn ($c) => self::waiting($c))->count(),
            'english' => $this->english($this->active()),
            'canAnswer' => QuotePartsPilot::canAnswer(auth()->user()),
        ]);
    }
}
