<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // 2026-05-21: поля для шаблонной email-подписи v2.
            'name_en' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:32'],
            'phone_extension' => ['nullable', 'string', 'max:16'],
            'mobile_phone' => ['nullable', 'string', 'max:32'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // Legacy plain-text override. Если заполнен — EmailSignatureService
            // отдаёт его как есть, шаблон не применяется.
            'email_signature' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
