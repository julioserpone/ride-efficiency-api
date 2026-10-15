<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $country_id
 * @property string|null $tax_id
 * @property string|null $tax_id_normalized
 * @property string|null $legal_name
 * @property string|null $commercial_name
 * @property string|null $address
 */
#[Fillable([
    'country_id',
    'tax_id',
    'tax_id_normalized',
    'legal_name',
    'commercial_name',
    'address',
])]
class Vendor extends Model
{
    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return HasMany<EnergyInvoice, $this>
     */
    public function energyInvoices(): HasMany
    {
        return $this->hasMany(EnergyInvoice::class);
    }
}
