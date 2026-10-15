<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app уведомление снабжению: поставщик прислал прайс через QuoteParts.
 * kind=quoteparts_price_list; клик — «Снабжение» → «Прайсы поставщиков».
 */
class QuotePartsPriceListNotification extends Notification
{
    use Queueable;

    /** @param array<string, int> $stats */
    public function __construct(public readonly int $priceListId, public readonly ?string $supplier, public readonly array $stats) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'quoteparts_price_list',
            'price_list_id' => $this->priceListId,
            'supplier' => $this->supplier ? mb_substr($this->supplier, 0, 120) : null,
            'offers' => (int) (($this->stats['exact'] ?? 0) + ($this->stats['accepted'] ?? 0)),
            'check' => (int) ($this->stats['check'] ?? 0),
            'none' => (int) ($this->stats['none'] ?? 0),
        ];
    }
}
