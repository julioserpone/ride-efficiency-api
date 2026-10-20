<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewEnergyInvoiceRequest;
use App\Http\Requests\StoreEnergyInvoiceRequest;
use App\Jobs\ProcessEncodedEnergyInvoiceOcrJob;
use App\Jobs\ProcessEnergyInvoiceOcrJob;
use App\Models\Country;
use App\Models\EnergyInvoice;
use App\Models\EnergyProduct;
use App\Services\VendorResolver;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EnergyInvoiceController extends Controller
{
    /**
     * Display a listing of energy invoices for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $invoices = $request->user()
            ->energyInvoices()
            ->with(['vendor', 'items.energyProduct'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json(['invoices' => $invoices]);
    }

    /**
     * Store an energy invoice image and dispatch OCR processing job.
     */
    public function store(StoreEnergyInvoiceRequest $request): JsonResponse
    {
        $user = $request->user();

        $image = $request->file('image');
        $path = $image?->store("energy_invoices/{$user->id}", 'local');
        $encodedImage = (string) $request->validated('image_base64', '');
        $countryId = $request->filled('country_id')
            ? $request->integer('country_id')
            : $user->country_id;
        $country = $countryId === null
            ? null
            : Country::query()->whereKey($countryId)->first();

        /** @var EnergyInvoice $invoice */
        $invoice = $user->energyInvoices()->create([
            'image_path' => $path ?? 'energy_invoices/'.$user->id.'/encoded-'.hash('sha256', $encodedImage).'.jpg',
            'status' => 'pending',
            'country_id' => $country?->id,
            'currency_code' => $country?->currency_code,
            'invoice_date' => $request->input('invoice_date'),
        ]);

        if ($image === null) {
            ProcessEncodedEnergyInvoiceOcrJob::dispatch($invoice, $encodedImage);
        } else {
            ProcessEnergyInvoiceOcrJob::dispatch($invoice);
        }

        return response()->json([
            'message' => 'Energy invoice uploaded successfully. OCR processing has been queued.',
            'invoice' => $invoice,
        ], 201);
    }

    /**
     * Display the specified energy invoice.
     */
    public function show(Request $request, EnergyInvoice $energyInvoice): JsonResponse
    {
        if ($energyInvoice->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'invoice' => $energyInvoice->load(['vendor', 'items.energyProduct']),
        ]);
    }

    /**
     * Save driver-confirmed fields when OCR could not fully classify an invoice.
     */
    public function review(
        ReviewEnergyInvoiceRequest $request,
        EnergyInvoice $energyInvoice,
        VendorResolver $vendorResolver,
    ): JsonResponse {
        if ($energyInvoice->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $attributes = $request->validated();
        $countryId = $request->integer('country_id');
        $country = Country::query()->whereKey($countryId)->firstOrFail();
        $productId = $request->filled('energy_product_id')
            ? $request->integer('energy_product_id')
            : null;
        $product = $productId !== null
            ? EnergyProduct::query()
                ->where('country_id', $country->id)
                ->whereKey($productId)
                ->first()
            : null;

        if ($productId !== null && $product === null) {
            throw ValidationException::withMessages([
                'energy_product_id' => 'The energy product must belong to the selected country.',
            ]);
        }

        if ($product !== null && (
            $product->energy_type !== EnergyType::from($attributes['energy_type'])
            || $product->energy_unit !== EnergyUnit::from($attributes['energy_unit'])
        )) {
            throw ValidationException::withMessages([
                'energy_product_id' => 'The product energy type and unit must match the reviewed invoice.',
            ]);
        }

        $isElectric = $attributes['energy_unit'] === EnergyUnit::KilowattHours->value;
        $invoiceDetails = Arr::only($attributes, [
            'vendor_name',
            'vendor_tax_id',
            'vendor_address',
            'station_name',
            'station_brand',
            'customer_name',
            'customer_tax_id',
            'customer_email',
            'payment_method',
            'vehicle_plate',
            'invoice_number',
            'authorization_number',
            'subtotal_amount',
            'tax_amount',
            'total_amount_without_subsidy',
        ]);

        DB::transaction(function () use (
            $energyInvoice,
            $attributes,
            $country,
            $product,
            $isElectric,
            $invoiceDetails,
            $vendorResolver,
        ): void {
            $vendorId = $energyInvoice->vendor_id;

            if (array_key_exists('vendor_tax_id', $attributes)) {
                $vendor = $vendorResolver->resolve($country, [
                    'tax_id' => $attributes['vendor_tax_id'],
                    'legal_name' => $attributes['vendor_name'] ?? $energyInvoice->vendor_name,
                    'commercial_name' => $attributes['vendor_commercial_name']
                        ?? $energyInvoice->vendor?->commercial_name,
                    'address' => $attributes['vendor_address'] ?? $energyInvoice->vendor_address,
                ], overwrite: true);
                $vendorId = $vendor?->id;
            } elseif (array_key_exists('vendor_name', $attributes)
                || array_key_exists('vendor_commercial_name', $attributes)
                || array_key_exists('vendor_address', $attributes)) {
                $vendor = $energyInvoice->vendor;

                if ($vendor !== null) {
                    $vendor->update(array_filter([
                        'legal_name' => $attributes['vendor_name'] ?? null,
                        'commercial_name' => $attributes['vendor_commercial_name'] ?? null,
                        'address' => $attributes['vendor_address'] ?? null,
                    ], static fn (?string $value): bool => $value !== null));
                }
            }

            $energyInvoice->update([
                'country_id' => $country->id,
                'vendor_id' => $vendorId,
                'energy_product_id' => $product?->id,
                'energy_type' => $attributes['energy_type'],
                'energy_unit' => $attributes['energy_unit'],
                'energy_amount' => $attributes['energy_amount'],
                'fuel_volume_units' => $isElectric ? null : $attributes['energy_amount'],
                'unit_price' => $attributes['unit_price'],
                'total_amount_paid' => $attributes['total_amount_paid'] ?? $energyInvoice->total_amount_paid,
                'currency_code' => $country->currency_code,
                'fuel_type' => $attributes['fuel_type'] ?? $product->name ?? $energyInvoice->fuel_type,
                'tax_rate' => $attributes['tax_rate'] ?? 0,
                'invoice_date' => $attributes['invoice_date'] ?? $energyInvoice->invoice_date,
                'status' => 'completed',
                ...$invoiceDetails,
            ]);

            if (isset($attributes['items'])) {
                $energyInvoice->items()->delete();
                $items = [];

                foreach ($attributes['items'] as $item) {
                    $items[] = [
                        ...$item,
                        'line_number' => count($items) + 1,
                    ];
                }

                $energyInvoice->items()->createMany($items);
            }
        });

        return response()->json([
            'message' => 'Energy invoice review saved successfully.',
            'invoice' => $energyInvoice->fresh()->load(['vendor', 'items.energyProduct']),
        ]);
    }

    /**
     * Remove the specified energy invoice and its stored image.
     */
    public function destroy(Request $request, EnergyInvoice $energyInvoice): JsonResponse
    {
        if ($energyInvoice->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        Storage::disk('local')->delete($energyInvoice->image_path);
        $energyInvoice->delete();

        return response()->json(['message' => 'Energy invoice deleted successfully.']);
    }
}
