<?php

namespace App\Models;

use Database\Factories\RideOfferScanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A screenshot processed by the offer OCR pipeline.
 *
 * Holds the retained image, the OCR output and the extraction produced on the
 * device and/or the server. The image is never exposed through the API.
 *
 * @property array<string, mixed>|null $ocr_tokens
 * @property array<string, mixed>|null $extracted
 * @property array<string, mixed>|null $client_extraction
 */
#[Fillable([
    'client_uuid',
    'user_id',
    'ride_offer_id',
    'image_path',
    'image_hash',
    'platform',
    'status',
    'provider',
    'ocr_text',
    'ocr_tokens',
    'extracted',
    'client_extraction',
    'reconciliation',
    'confidence',
    'error_code',
    'received_at',
    'expires_at',
])]
class RideOfferScan extends Model
{
    /** @use HasFactory<RideOfferScanFactory> */
    use HasFactory;

    public const STATUSES = ['pending', 'processing', 'parsed', 'review_required', 'failed', 'duplicate'];

    public const RECONCILIATIONS = ['confirmed', 'discrepancy', 'client_only', 'server_only'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocr_tokens' => 'array',
            'extracted' => 'array',
            'client_extraction' => 'array',
            'confidence' => 'decimal:4',
            'received_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<RideOffer, $this>
     */
    public function rideOffer(): BelongsTo
    {
        return $this->belongsTo(RideOffer::class);
    }
}
