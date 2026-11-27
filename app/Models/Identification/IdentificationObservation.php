<?php

namespace App\Models\Identification;

use Illuminate\Database\Eloquent\Model;

/** Вопрос из письма-уточнения менеджера (или отметка «письмо разобрано, вопросов нет»). */
class IdentificationObservation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['email_message_id', 'request_id', 'part_type_id', 'hint_id', 'part_type_raw', 'attribute_raw', 'question', 'purpose'];
}
