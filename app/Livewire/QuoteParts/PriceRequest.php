<?php

namespace App\Livewire\QuoteParts;

use App\Enums\Role;
use App\Livewire\Concerns\ComposesSupplierPriceRequest;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * «Запросить цены» в разделе «QuoteParts» (11.11.2026): та же панель «Запрос поставщикам», что в
 * «Снабжении» (ComposesSupplierPriceRequest) — группы и подбор поставщиков, превью письма по языкам
 * с названием, артикулом/OEM и M-артикулом, карточка товара с историей поставщиков, фото, мониторинг
 * цен. Позиции приходят отмеченными из вкладок «К актуализации» и «В QuoteParts»; количество не
 * обязательно (актуализация цен).
 */
class PriceRequest extends Component
{
    use ComposesSupplierPriceRequest;
    use WithFileUploads;

    /** cid => bool — позиции в запросе. */
    public array $selected = [];

    public static function canSend(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([
            Role::Procurement->value, Role::Manager->value,
            Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
        ]);
    }

    /** @param  list<int>  $cids */
    #[On('qp-price-request')]
    public function openFor(array $cids): void
    {
        abort_unless(self::canSend(), 403);
        foreach ($cids as $cid) {
            if ((int) $cid > 0) {
                $this->selected[(int) $cid] = true;
            }
        }
        if ($this->qpReplyBy === '') {
            $this->qpReplyBy = now()->addDays(7)->toDateString();
        }
        $this->resetErrorBag();
        unset($this->selectedPositions, $this->oemOptions, $this->supplierOptions, $this->previewLanguages);
        $this->prefillSelectedFields();
        $this->autoTranslateIfEnglish();
    }

    /** Выбора канала здесь нет: всегда маршрутизация через QuoteParts (системным — платформа, внесистемным — orders@). */
    public function updatedChannel(): void
    {
        $this->channel = 'mail';
    }

    /** Запрос ушёл — вкладки раздела снимают отметки. */
    protected function afterSupplierRequestReset(): void
    {
        $this->dispatch('qp-price-request-sent');
    }

    public function render()
    {
        return view('livewire.quoteparts.price-request');
    }
}
