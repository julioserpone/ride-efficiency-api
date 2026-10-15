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
            'vendor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vendor_tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'vendor_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vendor_commercial_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'station_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'station_brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:80'],
            'vehicle_plate' => ['sometimes', 'nullable', 'string', 'max:32'],
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'authorization_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subtotal_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tax_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'total_amount_without_subsidy' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_description' => ['nullable', 'string', 'max:255'],
            'items.*.item_code' => ['nullable', 'string', 'max:64'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'items.*.quantity_unit' => [
                'nullable',
                Rule::in(array_column(EnergyUnit::cases(), 'value')),
            ],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price_without_subsidy' => ['nullable', 'numeric', 'min:0'],
            'items.*.subsidy_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.subtotal_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_amount_without_subsidy' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
