<?php

namespace App\Livewire\Requests\Mail;

use App\Models\LetterTemplate;
use App\Services\Mail\LetterTemplateService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Модалка выбора шаблона для вставки в composer (вкладка «Переписка»).
 *
 * Регистрируется в detail.blade рядом с compose-form, ВНЕ таб-@switch —
 * переживает переключение вкладок. Открывается событием open-template-picker
 * (из кнопки «Вставить шаблон» в footer composer'а). При выборе шаблона
 * диспатчит insert-template → ComposeForm::insertTemplate.
 */
class TemplatePicker extends Component
{
    public int $requestId;
    public bool $open = false;

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    #[On('open-template-picker')]
    public function show(?int $requestId = null): void
    {
        if ($requestId !== null && $requestId !== $this->requestId) {
            return;
        }
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    #[Computed]
    public function tree()
    {
        return app(LetterTemplateService::class)->tree();
    }

    public function insert(int $id): void
    {
        $tpl = LetterTemplate::templates()->find($id);
        if (! $tpl) {
            return;
        }
        $this->dispatch(
            'insert-template',
            body: (string) $tpl->body,
            subject: (string) ($tpl->subject ?? ''),
            requestId: $this->requestId,
        );
        $this->open = false;
    }

    public function render()
    {
        return view('livewire.requests.mail.template-picker');
    }
}
