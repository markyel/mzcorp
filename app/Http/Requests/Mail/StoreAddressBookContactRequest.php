<?php

namespace App\Http\Requests\Mail;

use Illuminate\Foundation\Http\FormRequest;

/** Личный контакт адресной книги почты (AddressBookController::store). */
class StoreAddressBookContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
            'name' => ['nullable', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Укажите адрес.',
            'email.email' => 'Это не похоже на адрес почты.',
        ];
    }
}
