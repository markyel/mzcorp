<?php

namespace App\Livewire\Concerns;

use App\Models\QuotePartsRfq;
use App\Models\User;
use Livewire\Attributes\Url;

/**
 * Фильтр раздела QuoteParts «чьи запросы»: позиции, запрошенные у поставщиков конкретным
 * сотрудником (автор запроса QuoteParts — quoteparts_rfqs.created_by_user_id).
 *
 * Пусто в адресе — по умолчанию для пользователя: менеджеру и снабжению — свои запросы,
 * остальным (РОП, директорат, админ) — все.
 */
trait FiltersByRfqAuthor
{
    /** '' — по умолчанию для пользователя, 'all' — все, 'mine' — мои, '<id>' — запросы сотрудника */
    #[Url(as: 'au', except: '')]
    public string $author = '';

    public function authorFilter(): string
    {
        if ($this->author !== '') {
            return $this->author;
        }
        $u = auth()->user();

        return $u && $u->hasAnyRole(['manager', 'procurement']) && ! $u->hasAnyRole(['director', 'admin', 'head_of_sales']) ? 'mine' : 'all';
    }

    /** Чьи запросы показывать: id сотрудника или null — всех. */
    protected function authorId(): ?int
    {
        $f = $this->authorFilter();

        return match (true) {
            $f === 'mine' => (int) auth()->id(),
            ctype_digit($f) => (int) $f,
            default => null,
        };
    }

    /**
     * Авторы запросов за окно — для списка фильтра.
     *
     * @return array<int, string> id => имя
     */
    public function authorOptions(): array
    {
        $ids = QuotePartsRfq::query()->where('created_at', '>=', now()->subDays(60))->whereNotNull('created_by_user_id')
            ->distinct()->pluck('created_by_user_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
    }
}
