<?php

namespace App\Models\Kb;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KbAuditLog extends Model
{
    protected $table = 'kb_audit_log';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'action',
        'before',
        'after',
        'actor_id',
        'actor_type',
        'source',
        'reason',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
