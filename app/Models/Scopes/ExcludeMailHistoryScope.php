<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Письма из истории ящика (email_messages.is_history) видит только почтовый
 * клиент и синк — через EmailMessage::withHistory(). Всё остальное (конвейер
 * письма, заявки, детекторы КП/счетов, аналитика, отчёты менеджеров) работает
 * с живой перепиской и о миллионе архивных писем не знает.
 */
class ExcludeMailHistoryScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('is_history'), false);
    }
}
