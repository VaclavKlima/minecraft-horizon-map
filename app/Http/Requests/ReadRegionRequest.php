<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReadRegionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1024'],
            'include_nbt' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'limit.integer' => 'The limit must be an integer.',
            'limit.min' => 'The limit must be at least 1.',
            'limit.max' => 'The limit may not be greater than 1024.',
            'include_nbt.boolean' => 'The include_nbt field must be true or false.',
        ];
    }
}
