<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Привязка поставщика (org:{id} / sup:{id}) к коду контрагента 1С, поставленная человеком. */
class SupplierOnecCode extends Model
{
    protected $fillable = ['supplier_key', 'code', 'set_by_user_id'];

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(OnecCounterparty::class, 'code', 'code');
    }
}
