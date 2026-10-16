<?php

namespace App\Models;

use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $energy_invoice_id
 * @property int|null $energy_product_id
 * @property int $line_number
 * @property string|null $product_description
 * @property string|null $item_code
 * @property float|null $quantity
 * @property EnergyUnit|null $quantity_unit
 * @property float|null $unit_price
 * @property float|null $unit_price_without_subsidy
 * @property float|null $subsidy_amount
 * @property float|null $subtotal_amount
 * @property float|null $tax_rate
 * @property float|null $tax_amount
 * @property float|null $total_amount
 * @property float|null $total_amount_without_subsidy
 */
#[Fillable([
    'energy_invoice_id',
    'energy_product_id',
    'line_number',
    'product_description',
    'item_code',
    'quantity',
    'quantity_unit',
    'unit_price',
    'unit_price_without_subsidy',
    'subsidy_amount',
    'subtotal_amount',
    'tax_rate',
    'tax_amount',
    'total_amount',
    'total_amount_without_subsidy',
])]
class EnergyInvoiceItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_unit' => EnergyUnit::class,
            'unit_price' => 'decimal:4',
            'unit_price_without_subsidy' => 'decimal:4',
            'subsidy_amount' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'total_amount_without_subsidy' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<EnergyInvoice, $this>
     */
    public function energyInvoice(): BelongsTo
    {
        return $this->belongsTo(EnergyInvoice::class);
    }

    /**
     * @return BelongsTo<EnergyProduct, $this>
     */
    public function energyProduct(): BelongsTo
    {
        return $this->belongsTo(EnergyProduct::class);
    }
}
