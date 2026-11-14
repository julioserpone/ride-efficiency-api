<?php

namespace Database\Factories;

use App\Models\RideOfferScan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RideOfferScan>
 */
class RideOfferScanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'ride_offer_id' => null,
            'image_path' => 'ride_offers/scan/'.Str::uuid().'.jpg',
            'image_hash' => hash('sha256', Str::uuid()->toString()),
            'platform' => 'uber',
            'status' => 'pending',
            'provider' => null,
            'ocr_text' => null,
            'ocr_tokens' => null,
            'extracted' => null,
            'client_extraction' => null,
            'reconciliation' => null,
            'confidence' => null,
            'error_code' => null,
            'received_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }

    public function parsed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'parsed',
            'provider' => 'tesseract',
            'confidence' => 0.9200,
        ]);
    }

    public function failed(string $errorCode = 'unreadable'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'failed',
            'error_code' => $errorCode,
        ]);
    }
}
