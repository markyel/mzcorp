<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ручная классификация клиента «перепродавец» на уровне e-mail. НЕ путать с
 * DealerEmail (авто по потоку заявок, влияет на распределение). Перепродавец —
 * бизнес-статус, ставит/снимает менеджер; на распределение НЕ влияет.
 * См. App\Services\Request\ResellerEmailService.
 *
 * @property int $id
 * @property string $email
 * @property ?int $marked_by_user_id
 */
class ResellerEmail extends Model
{
    protected $fillable = [
        'email',
        'marked_by_user_id',
    ];

    protected $casts = [
        'marked_by_user_id' => 'integer',
    ];
}
