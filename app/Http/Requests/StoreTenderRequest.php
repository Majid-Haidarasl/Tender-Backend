<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenderRequest extends FormRequest
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
        return [
            'code' => 'required|string|max:255|unique:tenders,code',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'owner_name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'date' => 'required|date',
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
            'code.required' => 'کد مناقصه الزامی است.',
            'code.unique' => 'کد مناقصه تکراری است.',
            'title.required' => 'عنوان مناقصه الزامی است.',
            'owner_name.required' => 'عنوان مناقصه‌گزار الزامی است.',
            'type.required' => 'نوع مناقصه الزامی است.',
            'date.required' => 'تاریخ مناقصه الزامی است.',
            'date.date' => 'فرمت تاریخ نامعتبر است.',
        ];
    }
}

