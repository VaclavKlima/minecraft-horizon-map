<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReadMapRegionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'region' => ['nullable', 'string', 'regex:/^(all|r\.-?\d+\.-?\d+\.mca)$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'region.regex' => 'Region must be "all" or match r.<x>.<z>.mca.',
        ];
    }
}
