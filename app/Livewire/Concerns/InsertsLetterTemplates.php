<?php

namespace App\Livewire\Concerns;

use App\Models\LetterTemplate;
use App\Services\Mail\LetterTemplateService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Шаблоны писем в богатом редакторе (x-mail.rich-editor + livewire.mail._composer-templates-menu):
 * личная библиотека — та же, что во вкладке «Переписка» карточки заявки. Компонент держит тело
 * письма в $bodyHtml / $bodyText; тема ($subject), если она есть у компонента, заполняется из
 * шаблона, когда ещё пуста. После вставки — afterTemplateInserted() (композер почты сохраняет черновик).
 *
 * Используют: App\Livewire\Mail\Composer, App\Livewire\QuoteParts\SupportRequests.
 */
trait InsertsLetterTemplates
{
    /** @return Collection<int, LetterTemplate> */
    #[Computed]
    public function templateTree()
    {
        return app(LetterTemplateService::class)->tree((int) auth()->id());
    }

    /** Свои папки — выбор при «Сохранить как шаблон». */
    #[Computed]
    public function templateFolders()
    {
        return LetterTemplate::folders()
            ->ownedBy((int) auth()->id())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Вставить шаблон в тело. Редактор богатый (HTML), а шаблоны в библиотеке хранятся простым
     * текстом — поэтому переводим строки в абзацы и дописываем в конец, не затирая уже набранное.
     */
    public function insertTemplateById(int $id): void
    {
        $tpl = LetterTemplate::templates()
            ->ownedBy((int) auth()->id())
            ->find($id);
        if (! $tpl) {
            return;
        }

        $body = trim((string) $tpl->body);
        if ($body !== '') {
            $html = collect(preg_split('/\R/u', $body) ?: [])
                ->map(fn ($line) => '<p>'.($line === '' ? '<br>' : e($line)).'</p>')
                ->implode('');
            $this->bodyHtml = trim(strip_tags($this->bodyHtml)) === ''
                ? $html
                : rtrim($this->bodyHtml).$html;
            $this->bodyText = trim($this->htmlToPlain($this->bodyHtml));
        }

        if (property_exists($this, 'subject') && trim((string) $tpl->subject) !== '' && trim($this->subject) === '') {
            $this->subject = mb_substr((string) $tpl->subject, 0, 998);
        }

        $this->afterTemplateInserted();
        // Редактор хранит своё состояние в JS — просим подтянуть новое тело.
        $this->dispatch('mail-editor-set-html', html: $this->bodyHtml);
    }

    /** Сохранить набранное письмо в личную библиотеку шаблонов. */
    public function saveAsTemplate(string $name, ?int $parentId): void
    {
        $name = trim($name);
        $body = trim($this->htmlToPlain($this->bodyHtml));
        if ($name === '' || $body === '') {
            $this->dispatch('mail-composer-note', text: 'Нужны название шаблона и непустое письмо.');

            return;
        }

        app(LetterTemplateService::class)->saveFromLetter(
            name: $name,
            body: $body,
            parentId: $parentId,
            subject: property_exists($this, 'subject') && trim($this->subject) !== '' ? $this->subject : null,
            by: auth()->user(),
        );
        unset($this->templateTree, $this->templateFolders);
        $this->dispatch('mail-composer-note', text: 'Шаблон сохранён.');
    }

    protected function afterTemplateInserted(): void {}

    /** HTML → читаемый plain (для валидации и plain-альтернативы письма). */
    protected function htmlToPlain(string $html): string
    {
        $t = preg_replace('#<\s*br\s*/?>#i', "\n", $html) ?? $html;
        $t = preg_replace('#</\s*(p|div|li|h3|h4|blockquote)\s*>#i', "\n", $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;

        return trim($t);
    }
}
