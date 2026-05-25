<?php

namespace App\Livewire\Support;

use App\Services\Support\SupportTicketService;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Глобальная модалка «связь с создателем». Висит в layouts.navigation
 * рядом с bell. Открывается через Livewire-событие
 * `open-support-modal` с payload контекста (url, route, viewport, user_agent),
 * который собирается JS-обвязкой на момент клика.
 */
class NewTicketModal extends Component
{
    use WithFileUploads;

    public bool $open = false;

    #[Validate('nullable|string|max:200')]
    public string $subject = '';

    #[Validate('required|string|min:5|max:5000')]
    public string $body = '';

    /**
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    #[Validate([
        'attachments.*' => 'file|max:10240', // 10 МБ × файл
    ])]
    public array $attachments = [];

    /** @var array<string, mixed> */
    public array $context = [];

    public bool $sentSuccess = false;
    public ?int $sentTicketId = null;

    #[On('open-support-modal')]
    public function show(array $context = []): void
    {
        $this->reset(['subject', 'body', 'attachments', 'sentSuccess', 'sentTicketId']);
        $this->resetErrorBag();
        $this->context = $this->sanitizeContext($context);
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function save(SupportTicketService $service): void
    {
        $this->validate();

        $ticket = $service->createTicket(
            auth()->user(),
            [
                'subject' => $this->subject,
                'body' => $this->body,
                'context' => $this->context,
            ],
            $this->attachments,
        );

        $this->sentTicketId = $ticket->id;
        $this->sentSuccess = true;
        $this->reset(['subject', 'body', 'attachments']);
    }

    /**
     * Не доверяем браузеру вслепую — режем длины, выбрасываем неожиданные ключи.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $raw): array
    {
        return [
            'url' => isset($raw['url']) ? mb_substr((string) $raw['url'], 0, 500) : null,
            'route_name' => isset($raw['route_name']) ? mb_substr((string) $raw['route_name'], 0, 120) : null,
            'viewport' => isset($raw['viewport']) ? mb_substr((string) $raw['viewport'], 0, 32) : null,
            'user_agent' => isset($raw['user_agent']) ? mb_substr((string) $raw['user_agent'], 0, 500) : null,
            'referrer' => isset($raw['referrer']) ? mb_substr((string) $raw['referrer'], 0, 500) : null,
        ];
    }

    public function render()
    {
        return view('livewire.support.new-ticket-modal');
    }
}
