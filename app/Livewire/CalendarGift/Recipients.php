<?php

namespace App\Livewire\CalendarGift;

use App\Enums\MessengerChannel;
use App\Jobs\CalendarGift\RecheckCalendarGiftSubscriptionsJob;
use App\Models\CalendarGiftRecipient;
use App\Services\Bot\BotSettings;
use App\Services\CalendarGift\CalendarGiftFlow;
use App\Services\CalendarGift\CalendarGiftService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * «Календарь в подарок»: кто записался через бота (подписчики каналов), ссылки для рассылки, повторная проверка
 * подписок перед отправкой, статус «отправлен» (получателю — сообщение в бот), выгрузка в Excel.
 */
class Recipients extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'st', except: 'active')]
    public string $statusFilter = 'active';

    public ?string $note = null;

    public function setStatus(int $id, string $status, CalendarGiftService $gifts): void
    {
        $r = CalendarGiftRecipient::query()->where('campaign', $this->campaign())->findOrFail($id);
        $told = $gifts->setStatus($r, $status);
        $this->note = $status === CalendarGiftRecipient::STATUS_SENT
            ? $r->contact_name.': отмечен отправленным'.($told ? ', сообщили в бот.' : ', но написать в бот не удалось.')
            : $r->contact_name.': '.$r->statusLabel().'.';
        unset($this->recipients, $this->counts);
    }

    public function recheck(): void
    {
        RecheckCalendarGiftSubscriptionsJob::dispatch($this->campaign());
        $this->note = 'Проверяем подписки — обновите страницу через пару минут.';
    }

    public function campaign(): string
    {
        return app(CalendarGiftFlow::class)->campaign();
    }

    /** @return Collection<int, CalendarGiftRecipient> */
    #[Computed]
    public function recipients(): Collection
    {
        $term = trim($this->search);

        return CalendarGiftRecipient::query()->where('campaign', $this->campaign())
            ->when($this->statusFilter === 'active', fn ($q) => $q->where('status', '!=', CalendarGiftRecipient::STATUS_CANCELLED))
            ->when(array_key_exists($this->statusFilter, CalendarGiftRecipient::STATUSES), fn ($q) => $q->where('status', $this->statusFilter))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('contact_name', 'ilike', '%'.$term.'%')
                ->orWhere('company_name', 'ilike', '%'.$term.'%')->orWhere('phone', 'ilike', '%'.$term.'%')->orWhere('address', 'ilike', '%'.$term.'%')))
            ->with('organization:id,name')->orderByDesc('id')->get();
    }

    /** @return array{total:int, sent:int, unsubscribed:int, cancelled:int} */
    #[Computed]
    public function counts(): array
    {
        $all = CalendarGiftRecipient::query()->where('campaign', $this->campaign())->get(['status', 'subscribed']);
        $active = $all->where('status', '!=', CalendarGiftRecipient::STATUS_CANCELLED);

        return [
            'total' => $active->count(),
            'sent' => $active->where('status', CalendarGiftRecipient::STATUS_SENT)->count(),
            'unsubscribed' => $active->where('subscribed', false)->count(),
            'cancelled' => $all->where('status', CalendarGiftRecipient::STATUS_CANCELLED)->count(),
        ];
    }

    /** Ссылки на бота с меткой календаря — для письма клиентам и поста в канале. @return array<string, ?string> */
    #[Computed]
    public function links(): array
    {
        $settings = app(BotSettings::class);

        return collect(MessengerChannel::cases())->mapWithKeys(fn (MessengerChannel $c) => [
            $c->label() => ($url = $settings->publicUrl($c)) !== null ? $url.'?start=calendar' : null,
        ])->all();
    }

    public function render()
    {
        return view('livewire.calendar-gift.recipients', ['open' => app(CalendarGiftFlow::class)->isOpen(), 'until' => config('services.calendar_gift.until')]);
    }
}
