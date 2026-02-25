<?php

namespace App\Http\Requests\Alerts;

use App\Models\Alert;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStatusRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    Alert::HUMAN_STATUS_PENDING,
                    Alert::HUMAN_STATUS_REVIEWED,
                    Alert::HUMAN_STATUS_FLAGGED,
                    Alert::HUMAN_STATUS_RESOLVED,
                    Alert::HUMAN_STATUS_FALSE_POSITIVE,
                ]),
            ],
        ];
    }
}
