<?php

namespace App\Livewire\QuoteParts;

use App\Services\QuoteParts\QuotePartsAnswerTranslator;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsPilot;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Вкладка QuoteParts «Поддержка»: письма на support@quoteparts.net (обращения
 * QuoteParts на тему support). Пишут поставщики и те, кого среди них нет (новый поставщик, человек с
 * другой почты) — у таких нет карточки, только адрес. Ответ уходит письмом с support@ от «QuoteParts
 * Support» тому, кто писал; не по-русски — английский вариант, его можно поправить.
 */
class SupportRequests extends Component
{
    use WithFileUploads;

    /** Счётчик вкладки «Поддержка» (Index::supportWaitingCount); обновляется при каждой загрузке списка. */
    public const WAITING_CACHE = 'quoteparts:support:waiting';

    /** Причины закрытия (Conversation::RESOLUTIONS в QuoteParts) → подпись плашки. */
    public const RESOLUTIONS = ['resolved' => 'решено', 'no_action' => 'не требует внимания'];

    public bool $loaded = false;

    /** @var list<array<string, mixed>> */
    public array $conversations = [];

    public ?string $error = null;

    /** Показывать и закрытые. */
    public bool $withClosed = false;

    public ?int $activeId = null;

    public string $text = '';

    public string $textEn = '';

    /** @var array<int, mixed> */
    public array $files = [];

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
        $this->reset('text', 'textEn', 'files');
    }

    public function translate(QuotePartsAnswerTranslator $translator): void
    {
        $this->textEn = $translator->toEnglish($this->text) ?? $this->textEn;
    }

    public function reply(QuotePartsClient $client): void
    {
        abort_unless(QuotePartsPilot::canAnswer(auth()->user()), 403);
        $en = $this->english($this->active());
        $this->validate([
            'text' => ['required', 'string', 'max:20000'],
            'textEn' => [$en ? 'required' : 'nullable', 'string', 'max:20000'],
            'files.*' => ['file', 'max:20480'],
        ], [], ['text' => 'текст', 'textEn' => 'текст по-английски']);

        try {
            $client->sendConversationMessage((int) $this->activeId, $en ? $this->textEn : $this->text, auth()->user()->name, $this->uploads());
        } catch (Throwable $e) {
            $this->addError('text', mb_substr($e->getMessage(), 0, 300));

            return;
        }
        $this->reset('text', 'textEn', 'files');
        $this->load($client);
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
