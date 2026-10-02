<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Adjust based on your authorization logic
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenderId = $this->route('id');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('tenders', 'code')->ignore($tenderId),
            ],
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'owner_name' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|max:255',
            'date' => 'sometimes|date',
            'is_two_stage' => 'boolean',
            'normalize_prices' => 'boolean',
            'is_adjustable' => 'boolean',
            'base_period' => 'nullable|integer|min:1',
            'po_method' => 'nullable|string|in:simple,weighted',
            'tgamma' => 'nullable|numeric|min:0|max:1',
            'tbeta' => 'nullable|numeric|min:0|max:1',
            'delta' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:draft,active,completed,cancelled',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'کد مناقصه تکراری است.',
            'date.date' => 'فرمت تاریخ نامعتبر است.',
        ];
    }
}

