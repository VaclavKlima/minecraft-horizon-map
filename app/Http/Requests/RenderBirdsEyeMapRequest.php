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
            'focus_world_x' => ['sometimes', 'integer'],
            'focus_world_z' => ['sometimes', 'integer'],
            'viewport_min_world_x' => ['sometimes', 'integer'],
            'viewport_min_world_z' => ['sometimes', 'integer'],
            'viewport_max_world_x' => ['sometimes', 'integer'],
            'viewport_max_world_z' => ['sometimes', 'integer'],
            'priority_regions' => ['sometimes', 'array'],
            'priority_regions.*' => ['string', 'regex:/^r\.-?\d+\.-?\d+\.mca$/'],
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
