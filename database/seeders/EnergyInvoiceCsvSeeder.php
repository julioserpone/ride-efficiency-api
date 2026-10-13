<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\EnergyInvoice;
use App\Models\EnergyProduct;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SplFileObject;

class EnergyInvoiceCsvSeeder extends Seeder
{
    private const LOCAL_CSV_PATH = 'tests/Flujo de pagos Gasolina 2026.csv';

    private const FIXTURE_CSV_PATH = 'tests/Fixtures/energy-invoices.csv';

    /**
     * Import test invoices and map the supplier data from the CSV.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The invoice CSV seeder is only available in local and testing environments.');
        }

        $csvRelativePath = app()->environment('testing')
            ? self::FIXTURE_CSV_PATH
            : (is_file(base_path(self::LOCAL_CSV_PATH)) ? self::LOCAL_CSV_PATH : self::FIXTURE_CSV_PATH);
        $csvPath = base_path($csvRelativePath);

        if (! is_file($csvPath)) {
            throw new RuntimeException('The invoice CSV file is missing: '.$csvRelativePath);
        }

        $country = Country::query()->where('code', 'EC')->firstOrFail();
        $product = EnergyProduct::query()
            ->whereBelongsTo($country)
            ->where('code', 'super')
            ->firstOrFail();
        $user = User::query()->where('email', 'test@example.com')->firstOrFail();

        DB::transaction(function () use ($country, $csvPath, $product, $user): void {
            $csv = new SplFileObject($csvPath);
            $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
            $csv->setCsvControl(',', '"', '');

            $headers = $csv->fgetcsv();

            if (! is_array($headers) || $headers === [null]) {
                throw new RuntimeException('The invoice CSV file is empty.');
            }

            $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");
            $requiredHeaders = [
                'Fecha',
                'Hora',
                'RUC',
                'Razón social',
                'Galones',
                'Valor Unitario',
                'Monto',
                'Kilometraje',
            ];

            if (array_diff($requiredHeaders, $headers) !== []) {
                throw new RuntimeException('The invoice CSV is missing required columns.');
            }

            foreach ($csv as $lineNumber => $values) {
                if ($lineNumber === 0 || ! is_array($values) || $values === [null]) {
                    continue;
                }

                if (count($values) !== count($headers)) {
                    throw new RuntimeException('The invoice CSV has an invalid number of columns on row '.($lineNumber + 1).'.');
                }

                $row = array_combine($headers, $values);

                $invoiceDate = DateTimeImmutable::createFromFormat(
                    '!Y-m-d H:i:s',
                    $row['Fecha'].' '.$row['Hora'],
                );
                $dateErrors = DateTimeImmutable::getLastErrors();

                if (
                    $invoiceDate === false
                    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                ) {
                    Log::warning('Skipping energy invoice CSV row with an invalid date.', [
                        'csv_row' => $lineNumber + 1,
                        'date' => $row['Fecha'],
                    ]);

                    continue;
                }

                foreach (['Galones', 'Valor Unitario', 'Monto'] as $numericColumn) {
                    if (! is_numeric($row[$numericColumn])) {
                        throw new RuntimeException('The invoice CSV contains an invalid amount on row '.($lineNumber + 1).'.');
                    }
                }

                $invoiceDate = CarbonImmutable::instance($invoiceDate);
                $volume = (float) $row['Galones'];
                $taxRate = 15.0;

                EnergyInvoice::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'invoice_date' => $invoiceDate,
                        'vendor_tax_id' => $row['RUC'],
                        'total_amount_paid' => round((float) $row['Monto'], 2),
                    ],
                    [
                        'country_id' => $country->id,
                        'image_path' => 'seeded/energy-invoice-csv/no-original-image',
                        'total_amount_paid' => round((float) $row['Monto'], 2),
                        'energy_amount' => $volume,
                        'energy_unit' => EnergyUnit::Gallons,
                        'currency_code' => $country->currency_code,
                        'energy_product_id' => $product->id,
                        'fuel_volume_units' => $volume,
                        'fuel_type' => 'Súper',
                        'energy_type' => EnergyType::PremiumGasoline,
                        'unit_price' => (float) $row['Valor Unitario'],
                        'odometer' => $row['Kilometraje'] === '' ? null : (int) $row['Kilometraje'],
                        'vendor_tax_id' => $row['RUC'],
                        'vendor_name' => $row['Razón social'],
                        'tax_rate' => $taxRate,
                        'invoice_date' => $invoiceDate,
                        'status' => 'completed',
                    ],
                );
            }
        });
    }
}
