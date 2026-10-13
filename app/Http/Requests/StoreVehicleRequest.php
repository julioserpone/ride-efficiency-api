<?php

namespace App\Http\Requests;

use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
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
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'name' => ['required', 'string', 'max:100'],
            'energy_type' => ['required', Rule::enum(EnergyType::class)],
            'energy_unit' => [
                'required',
                Rule::in($this->input('energy_type') === EnergyType::Electric->value
                    ? [EnergyUnit::KilowattHours->value]
                    : [EnergyUnit::Gallons->value, EnergyUnit::Litres->value]),
            ],
            'energy_economy' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'commission_rate' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
