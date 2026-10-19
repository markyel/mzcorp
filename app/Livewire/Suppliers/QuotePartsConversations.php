<?php

namespace App\Livewire\Suppliers;

use App\Models\Supplier;
use App\Services\QuoteParts\QuotePartsAnswerTranslator;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsPilot;
use App\Services\QuoteParts\QuotePartsRfqService;
use App\Services\QuoteParts\QuotePartsSupplierService;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Карточка поставщика → «Обращения»: переписка с поставщиком через QuoteParts вне запросов
 * цен (фото перед отгрузкой, рекламации, финансы). Письмо уходит его людям по теме от MyZip;
 * ответ — письмом или из его кабинета. Пишем по-русски; англоязычному поставщику (язык в
 * карточке) — перевод, который можно поправить. Данные живут в QuoteParts, здесь — показ.
 */
class QuotePartsConversations extends Component
{
    use WithFileUploads;

    public const TOPICS = [
        'logistics' => 'Логистика, фото перед отгрузкой',
        'claims' => 'Рекламация',
        'finance' => 'Финансы',
        'general' => 'Общее',
    ];

    public Supplier $supplier;

    public bool $loaded = false;

    /** @var list<array<string, mixed>> */
    public array $conversations = [];

    public ?string $error = null;

    public ?int $activeId = null;

    public bool $composing = false;

    public string $topic = 'logistics';

    public string $subject = '';

    public string $subjectEn = '';

    public string $text = '';

    public string $textEn = '';

    /** @var array<int, mixed> */
    public array $files = [];

    public function mount(Supplier $supplier): void
    {
        // Карточка поставщика показывает обращения при включённом пилоте (canConverse); уточнить уже
        // пришедшее предложение (вкладка «Актуализация цен») можно и при выключенном — как ответ по запросу.
        abort_unless(QuotePartsPilot::canConverse(auth()->user()) || QuotePartsPilot::canAnswer(auth()->user()), 403);
        $this->supplier = $supplier;
    }

    public function load(QuotePartsClient $client): void
    {
        $this->error = null;
        if ($this->supplier->quoteparts_link_id === null) {
            $this->conversations = [];
            $this->loaded = true;

            return;
        }
        try {
            $this->conversations = $client->conversations($this->supplier->quotepartsKey());
        } catch (Throwable $e) {
            $this->error = 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 200);
        }
        $this->loaded = true;
    }

    public function english(): bool
    {
        return app(QuotePartsRfqService::class)->supplierLanguages([$this->supplier->quotepartsKey()])[$this->supplier->quotepartsKey()] === 'en';
    }

    public function translate(QuotePartsAnswerTranslator $translator): void
    {
        $this->subjectEn = $translator->toEnglish($this->subject) ?? $this->subjectEn;
        $this->textEn = $translator->toEnglish($this->text) ?? $this->textEn;
    }

    public function start(QuotePartsClient $client, QuotePartsSupplierService $suppliers): void
    {
        $en = $this->english();
        $this->validate([
            'topic' => ['required', 'in:'.implode(',', array_keys(self::TOPICS))],
            'subject' => ['required', 'string', 'max:500'],
            'text' => ['required', 'string', 'max:20000'],
            'subjectEn' => [$en ? 'required' : 'nullable', 'string', 'max:500'],
            'textEn' => [$en ? 'required' : 'nullable', 'string', 'max:20000'],
            'files.*' => ['file', 'max:20480'],
        ], [], ['subject' => 'тема', 'text' => 'текст', 'subjectEn' => 'тема по-английски', 'textEn' => 'текст по-английски']);

        try {
            $suppliers->ensureRegistered($this->supplier); // режим переписки, если ещё не в QuoteParts
            $client->startConversation([
                'supplier' => $this->supplier->quotepartsKey(),
                'topic' => $this->topic,
                'subject' => $en ? $this->subjectEn : $this->subject,
                'text' => $en ? $this->textEn : $this->text,
                'author_ref' => 'user:'.auth()->id(),
                'author_name' => auth()->user()->name,
            ], $this->uploads());
        } catch (Throwable $e) {
            $this->addError('text', mb_substr($e->getMessage(), 0, 300));

            return;
        }

        $this->reset('composing', 'subject', 'subjectEn', 'text', 'textEn', 'files');
        $this->load($client);
        $this->dispatch('toast', type: 'success', message: 'Обращение отправлено поставщику.');
    }

    public function open(int $id): void
    {
        $this->activeId = $this->activeId === $id ? null : $id;
        $this->reset('text', 'textEn', 'files');
    }

    public function reply(QuotePartsClient $client): void
    {
        $en = $this->english();
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
    }

    public function close(int $id, QuotePartsClient $client): void
    {
        try {
            $client->closeConversation($id);
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 200));
        }
        $this->load($client);
    }

    /** @return list<array{path:string, name:string}> */
    private function uploads(): array
    {
        return collect($this->files)->filter()->map(fn ($f) => ['path' => $f->getRealPath(), 'name' => $f->getClientOriginalName()])->values()->all();
    }

    public function render()
    {
        return view('livewire.suppliers.quote-parts-conversations', ['english' => $this->loaded ? $this->english() : false]);
    }
}
