<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EnergyInvoiceFactory;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $country_id
 * @property string $image_path
 * @property float|null $total_amount_paid
 * @property float|null $energy_amount
 * @property EnergyUnit|null $energy_unit
 * @property string|null $currency_code
 * @property int|null $energy_product_id
 * @property float|null $fuel_volume_units
 * @property string|null $fuel_type
 * @property EnergyType|null $energy_type
 * @property float|null $unit_price
 * @property int|null $odometer
 * @property string|null $vendor_tax_id
 * @property string|null $vendor_name
 * @property float|null $tax_rate
 * @property CarbonImmutable|null $invoice_date
 * @property string $status
 * @property string|null $ocr_raw_text
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'user_id',
    'country_id',
    'image_path',
    'total_amount_paid',
    'energy_amount',
    'energy_unit',
    'currency_code',
    'energy_product_id',
    'fuel_volume_units',
    'fuel_type',
    'energy_type',
    'unit_price',
    'odometer',
    'vendor_tax_id',
    'vendor_name',
    'tax_rate',
    'invoice_date',
    'status',
    'ocr_raw_text',
])]
class EnergyInvoice extends Model
{
    /** @use HasFactory<EnergyInvoiceFactory> */
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
            'energy_amount' => 'decimal:3',
            'fuel_volume_units' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'odometer' => 'integer',
            'tax_rate' => 'decimal:2',
            'energy_type' => EnergyType::class,
            'energy_unit' => EnergyUnit::class,
            'invoice_date' => 'datetime',
        ];
    }

    /**
     * Get the user who owns the energy invoice.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the country where the purchase was made.
     *
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Get the country-specific energy product identified on the invoice.
     *
     * @return BelongsTo<EnergyProduct, $this>
     */
    public function energyProduct(): BelongsTo
    {
        return $this->belongsTo(EnergyProduct::class);
    }

    /**
     * Whether the invoice replenished an electric vehicle.
     */
    public function isElectric(): bool
    {
        return $this->energy_type?->isElectric() ?? false;
    }
}
