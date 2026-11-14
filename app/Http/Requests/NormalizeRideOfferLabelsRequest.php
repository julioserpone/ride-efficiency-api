<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NormalizeRideOfferLabelsRequest extends FormRequest
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
            'platform' => ['nullable', 'string', 'max:32'],
            'service_type' => ['nullable', 'string', 'max:64'],
            'availability_type' => ['nullable', 'string', 'max:32'],
        ];
    }
}
