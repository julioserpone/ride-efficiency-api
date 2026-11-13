<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncRideOffersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'offers' => ['required', 'array', 'min:1', 'max:100'],
            'offers.*.client_uuid' => ['required', 'uuid'],
            'offers.*.vehicle_id' => ['nullable', 'integer'],
            'offers.*.daily_shift_id' => ['nullable', 'integer'],
            'offers.*.platform' => ['required', 'string', 'max:32'],
            'offers.*.service_type' => ['nullable', 'string', 'max:32'],
            'offers.*.availability_type' => ['nullable', 'string', 'max:32'],
            'offers.*.rider_rating' => ['nullable', 'numeric', 'between:0,5'],
            'offers.*.rider_trip_count' => ['nullable', 'integer', 'min:0'],
            'offers.*.rider_verified' => ['nullable', 'boolean'],
            'offers.*.payment_method' => ['nullable', 'string', 'max:32'],
            'offers.*.external_offer_id' => ['nullable', 'string', 'max:128'],
            'offers.*.currency_code' => ['required', 'string', 'size:3'],
            'offers.*.offered_fare' => ['required', 'numeric'],
            'offers.*.surge_amount' => ['nullable', 'numeric'],
            'offers.*.tip_amount' => ['nullable', 'numeric'],
            'offers.*.toll_amount' => ['nullable', 'numeric'],
            'offers.*.estimated_distance_km' => ['required', 'numeric', 'gt:0'],
            'offers.*.destination_address' => ['nullable', 'string', 'max:255'],
            'offers.*.estimated_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'offers.*.pickup_distance_km' => ['nullable', 'numeric'],
            'offers.*.pickup_address' => ['nullable', 'string', 'max:255'],
            'offers.*.estimated_pickup_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'offers.*.actual_distance_km' => ['nullable', 'numeric'],
            'offers.*.actual_fare' => ['nullable', 'numeric'],
            'offers.*.actual_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'offers.*.source' => ['nullable', 'string', 'max:32'],
            'offers.*.ocr_confidence' => ['nullable', 'numeric', 'between:0,1'],
            'offers.*.raw_payload' => ['nullable', 'array'],
            'offers.*.status' => ['nullable', 'string', 'max:32'],
            'offers.*.expires_at' => ['nullable', 'date'],
            'offers.*.received_at' => ['required', 'date'],
            'offers.*.decided_at' => ['nullable', 'date'],
            'offers.*.completed_at' => ['nullable', 'date'],
            'offers.*.cancelled_at' => ['nullable', 'date'],
            'offers.*.cancellation_reason' => ['nullable', 'string', 'max:128'],
            'offers.*.client_verdict' => ['nullable', 'string', 'max:16'],
            'offers.*.client_net_profit_estimate' => ['nullable', 'numeric'],
            'offers.*.client_break_even_fare' => ['nullable', 'numeric'],
            'offers.*.calculation_version' => ['nullable', 'string', 'max:16'],
            'offers.*.config_snapshot' => ['nullable', 'array'],
            'offers.*.events' => ['nullable', 'array'],
            'offers.*.events.*.event_type' => ['required_with:offers.*.events', 'string', 'max:64'],
            'offers.*.events.*.occurred_at' => ['required_with:offers.*.events', 'date'],
            'offers.*.events.*.metadata' => ['nullable', 'array'],
        ];
    }
}
