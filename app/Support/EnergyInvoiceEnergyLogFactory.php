<?php

namespace App\Support;

use App\Models\EnergyInvoice;
use GigEconomics\Domain\Energy\EnergyLog;
use GigEconomics\Domain\Energy\EnergyPrice;
use GigEconomics\Domain\Energy\EnergyPurchase;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\EnergyQuantity;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use GigEconomics\Support\Configuration;
use Illuminate\Support\Collection;

/**
 * Translates stored energy invoices into the library's domain objects.
 *
 * This is the single adapter between persistence and the
 * `julioserpone/gig-economics` domain: an invoice row knows about the database,
 * while {@see EnergyPurchase} knows about the energy economy. Keeping the
 * translation in one place means the domain is fed with well-formed values and
 * the controllers never build a domain object by hand.
 *
 * The same factory serves combustion and electric vehicles: the invoice's
 * explicit `energy_unit` selects the quantity and price factory, while
 * `energy_type` verifies that the purchase belongs to the same energy dimension.
 */
final class EnergyInvoiceEnergyLogFactory
{
    public function __construct(private readonly Configuration $configuration = new Configuration) {}

    /**
     * Build the energy log for one user's invoices.
     *
     * Only completed invoices carrying both an energy amount and a unit price
     * can become purchases: anything still being processed has nothing to price
     * yet, and including it would silently skew the weighted price.
     *
     * @param  Collection<int, EnergyInvoice>|array<int, EnergyInvoice>  $invoices
     */
    public function fromInvoices(Collection|array $invoices): EnergyLog
    {
        $purchases = [];

        foreach ($invoices as $invoice) {
            $purchase = $this->fromInvoice($invoice);

            if ($purchase instanceof EnergyPurchase) {
                $purchases[] = $purchase;
            }
        }

        return EnergyLog::fromPurchases($purchases);
    }

    /**
     * Build a single purchase, or null when the invoice cannot be priced.
     */
    public function fromInvoice(EnergyInvoice $invoice): ?EnergyPurchase
    {
        $energyAmount = $this->normalizedAmount($invoice->energy_amount ?? $invoice->fuel_volume_units);
        $unitPrice = $this->normalizedAmount($invoice->unit_price);
        $energyType = $this->energyTypeFor($invoice);
        $energyUnit = $invoice->energy_unit;

        if (
            $energyAmount === null
            || $unitPrice === null
            || $energyType === null
            || $energyUnit === null
            || $invoice->status !== 'completed'
            || $energyType->isElectric() !== $energyUnit->isElectric()
        ) {
            return null;
        }

        return EnergyPurchase::create(
            energy: EnergyQuantity::of($energyAmount, $energyUnit),
            unitPrice: $this->priceFor($unitPrice, $energyUnit),
            purchasedAt: $invoice->invoice_date,
            vendorTaxId: $invoice->vendor_tax_id,
            vendorName: $invoice->vendor_name,
            odometer: $invoice->odometer,
            energyType: $energyType,
            taxRate: $this->taxRateFor($invoice),
            reference: 'energy-invoice-'.$invoice->getKey(),
        );
    }

    /**
     * The energy source of an invoice, or null when it has not been classified.
     */
    public function energyTypeFor(EnergyInvoice $invoice): ?EnergyType
    {
        return $invoice->energy_type;
    }

    /**
     * The tax rate applied to the purchase, in percentage points. The invoice
     * carries its own rate when the OCR pass read it; otherwise the configured
     * default is used so the total stays reproducible.
     */
    public function taxRateFor(EnergyInvoice $invoice): Percentage
    {
        $rate = $this->normalizedAmount($invoice->tax_rate);

        return Percentage::of($rate ?? (string) $this->configuration->defaultTaxRatePercent);
    }

    /**
     * Price of one unit of energy, in the unit the energy type is measured in.
     */
    private function priceFor(string $amount, EnergyUnit $energyUnit): EnergyPrice
    {
        return match ($energyUnit) {
            EnergyUnit::KilowattHours => EnergyPrice::perKilowattHour($amount),
            EnergyUnit::Litres => EnergyPrice::perLitre($amount),
            EnergyUnit::Gallons => EnergyPrice::perGallon($amount),
        };
    }

    /**
     * Normalize a database value to a plain decimal string, or null when the
     * column is empty. Values are kept as strings so they travel through the
     * library as arbitrary-precision decimals instead of floats.
     */
    private function normalizedAmount(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return is_numeric($normalized) ? $normalized : null;
    }
}
