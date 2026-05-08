<?php

namespace App\Models\Kb;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentificationRuleAlternative extends Model
{
    protected $table = 'identification_rule_alternatives';

    protected $fillable = [
        'rule_id',
        'required_parameter_ids',
        'label',
        'preference_order',
    ];

    protected $casts = [
        'required_parameter_ids' => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(IdentificationRule::class, 'rule_id');
    }
}
