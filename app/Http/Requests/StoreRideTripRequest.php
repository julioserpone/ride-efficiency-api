<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRideTripRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $vehicle = $this->route('vehicle');

        return $vehicle instanceof Vehicle && $vehicle->user_id === $this->user()?->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'platform_name' => ['required', 'string', 'max:100'],
            'gross_fare' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'distance_km' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'deadhead_distance_km' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'commission_rate' => ['nullable', 'numeric', 'between:0,100'],
            'energy_price_per_unit' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'other_costs_per_trip' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'daily_shift_id' => [
                'nullable',
                'integer',
                Rule::exists('daily_shifts', 'id')->where('user_id', $this->user()?->id),
            ],
        ];
    }
}
