<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenderBirdsEyeMapRequest extends FormRequest
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
            'heightmap' => [
                'sometimes',
                'string',
                'in:WORLD_SURFACE,WORLD_SURFACE_WG,OCEAN_FLOOR,OCEAN_FLOOR_WG,MOTION_BLOCKING,MOTION_BLOCKING_NO_LEAVES',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'heightmap.in' => 'Unsupported heightmap type.',
        ];
    }
}
