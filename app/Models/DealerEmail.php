<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Auto-marked dealer email. См. DealerEmailService и AssignmentService::pickStickyByClientEmail.
 *
 * @property int $id
 * @property string $email
 * @property int $open_count_at_mark
 * @property \Illuminate\Support\Carbon $marked_at
 */
class DealerEmail extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'open_count_at_mark',
        'marked_at',
    ];

    protected $casts = [
        'open_count_at_mark' => 'integer',
        'marked_at' => 'datetime',
    ];
}
