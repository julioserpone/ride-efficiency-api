<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewEnergyInvoiceRequest;
use App\Http\Requests\StoreEnergyInvoiceRequest;
use App\Jobs\ProcessEnergyInvoiceOcrJob;
use App\Models\Country;
use App\Models\EnergyInvoice;
use App\Models\EnergyProduct;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $path = $request->file('image')->store(
            "energy_invoices/{$user->id}",
            'local'
        );
        $countryId = $request->filled('country_id')
            ? $request->integer('country_id')
            : $user->country_id;
        $country = $countryId === null
            ? null
            : Country::query()->whereKey($countryId)->first();

        /** @var EnergyInvoice $invoice */
        $invoice = $user->energyInvoices()->create([
            'image_path' => $path,
            'status' => 'pending',
            'country_id' => $country?->id,
            'currency_code' => $country?->currency_code,
            'invoice_date' => $request->input('invoice_date'),
        ]);

        ProcessEnergyInvoiceOcrJob::dispatch($invoice);

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

        return response()->json(['invoice' => $energyInvoice]);
    }

    /**
     * Save driver-confirmed fields when OCR could not fully classify an invoice.
     */
    public function review(ReviewEnergyInvoiceRequest $request, EnergyInvoice $energyInvoice): JsonResponse
    {
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

        $energyInvoice->update([
            'country_id' => $country->id,
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
        ]);

        return response()->json([
            'message' => 'Energy invoice review saved successfully.',
            'invoice' => $energyInvoice->fresh(),
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
