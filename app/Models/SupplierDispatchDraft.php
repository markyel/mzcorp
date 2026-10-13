<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Черновик запроса поставщикам (SupplierDispatchPanel): состояние формы,
 * пока менеджер не отправил запросы. Один на менеджера и заявку.
 */
class SupplierDispatchDraft extends Model
{
    protected $fillable = ['request_id', 'user_id', 'payload'];

    protected $casts = ['payload' => 'array'];
}
