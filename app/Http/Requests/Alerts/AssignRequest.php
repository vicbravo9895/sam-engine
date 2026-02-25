<?php

namespace App\Http\Requests\Alerts;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (empty($this->user_id) && empty($this->contact_id)) {
                $validator->errors()->add(
                    'user_id',
                    'Se requiere user_id o contact_id'
                );
            }
        });
    }
}
