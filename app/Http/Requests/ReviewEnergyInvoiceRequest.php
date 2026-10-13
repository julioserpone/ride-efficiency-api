<?php

namespace App\Http\Requests;

use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewEnergyInvoiceRequest extends FormRequest
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
            'energy_type' => ['required', Rule::enum(EnergyType::class)],
            'energy_unit' => [
                'required',
                Rule::in($this->input('energy_type') === EnergyType::Electric->value
                    ? [EnergyUnit::KilowattHours->value]
                    : [EnergyUnit::Gallons->value, EnergyUnit::Litres->value]),
            ],
            'energy_amount' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'gt:0'],
            'total_amount_paid' => ['nullable', 'numeric', 'min:0'],
            'energy_product_id' => ['nullable', 'integer', 'exists:energy_products,id'],
            'fuel_type' => ['nullable', 'string', 'max:120'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'invoice_date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
