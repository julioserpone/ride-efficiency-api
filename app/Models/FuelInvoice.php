<?php

namespace App\Models;

use Database\Factories\FuelInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $image_path
 * @property float|null $total_amount_paid
 * @property float|null $fuel_volume_units
 * @property string|null $fuel_type
 * @property Carbon|null $invoice_date
 * @property string $status
 * @property string|null $ocr_raw_text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'image_path',
    'total_amount_paid',
    'fuel_volume_units',
    'fuel_type',
    'invoice_date',
    'status',
    'ocr_raw_text',
])]
class FuelInvoice extends Model
{
    /** @use HasFactory<FuelInvoiceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount_paid' => 'decimal:2',
            'fuel_volume_units' => 'decimal:2',
            'invoice_date' => 'datetime',
        ];
    }

    /**
     * Get the user who owns the fuel invoice.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
