<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CloseShiftRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'total_km_gps' => ['nullable', 'numeric', 'min:0'],
            'total_minutes_connected' => ['nullable', 'integer', 'min:0'],
            'total_trips_completed' => ['nullable', 'integer', 'min:0'],
            'total_offers_scanned' => ['nullable', 'integer', 'min:0'],
            'applied_fuel_cost' => ['nullable', 'numeric', 'min:0'],
            'estimated_depreciation' => ['nullable', 'numeric', 'min:0'],
            'earnings' => ['nullable', 'array'],
            'earnings.*.provider_name' => ['required_with:earnings', 'string', 'max:100'],
            'earnings.*.gross_amount' => ['required_with:earnings', 'numeric', 'min:0'],
        ];
    }
}
