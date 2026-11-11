<?php

namespace App\Livewire\Concerns;

use App\Models\QuotePartsRfq;
use App\Models\User;
use Livewire\Attributes\Url;

/**
 * Фильтр раздела QuoteParts «чьи запросы»: позиции, запрошенные у поставщиков конкретным
 * сотрудником (автор запроса QuoteParts — quoteparts_rfqs.created_by_user_id).
 *
 * Пусто в адресе — по умолчанию для пользователя: менеджеру — свои запросы,
 * остальным (снабжение, РОП, директорат, админ) — все.
 */
trait FiltersByRfqAuthor
{
    /** '' — по умолчанию для пользователя, 'all' — все, 'mine' — мои, '<id>' — запросы сотрудника */
    #[Url(as: 'au', except: '')]
    public string $author = '';

    /**
     * Действующий фильтр — сразу в поле: пустое значение в списке не найти, и браузер показывал
     * первый пункт «Все», хотя работало «Мои»; выбрать «Все» было нельзя — он уже «выбран»
     * (снабжение, 11.11.2026). Livewire вызывает mount{Трейт} при открытии компонента.
     */
    public function mountFiltersByRfqAuthor(): void
    {
        if ($this->author === '') {
            $this->author = $this->defaultAuthorFilter();
        }
    }

    public function authorFilter(): string
    {
        return $this->author !== '' ? $this->author : $this->defaultAuthorFilter();
    }

    /**
     * По умолчанию: менеджеру — свои запросы, остальным — все. Снабжению тоже все: оно ведёт
     * актуализацию цен по всем запросам, не только по своим (заказчик, 11.11.2026).
     */
    private function defaultAuthorFilter(): string
    {
        $u = auth()->user();

        return $u && $u->hasRole('manager') && ! $u->hasAnyRole(['procurement', 'director', 'admin', 'head_of_sales']) ? 'mine' : 'all';
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
