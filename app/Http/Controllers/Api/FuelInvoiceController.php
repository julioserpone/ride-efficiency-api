<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFuelInvoiceRequest;
use App\Jobs\ProcessFuelInvoiceOcrJob;
use App\Models\FuelInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FuelInvoiceController extends Controller
{
    /**
     * Display a listing of fuel invoices for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $invoices = $request->user()
            ->fuelInvoices()
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json(['invoices' => $invoices]);
    }

    /**
     * Store a new fuel invoice image and dispatch OCR processing job.
     */
    public function store(StoreFuelInvoiceRequest $request): JsonResponse
    {
        $user = $request->user();

        $path = $request->file('image')->store(
            "fuel_invoices/{$user->id}",
            'local'
        );

        /** @var FuelInvoice $invoice */
        $invoice = $user->fuelInvoices()->create([
            'image_path' => $path,
            'status' => 'pending',
            'invoice_date' => $request->input('invoice_date'),
        ]);

        ProcessFuelInvoiceOcrJob::dispatch($invoice);

        return response()->json([
            'message' => 'Fuel invoice uploaded successfully. OCR processing has been queued.',
            'invoice' => $invoice,
        ], 201);
    }

    /**
     * Display the specified fuel invoice.
     */
    public function show(Request $request, FuelInvoice $fuelInvoice): JsonResponse
    {
        if ($fuelInvoice->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json(['invoice' => $fuelInvoice]);
    }

    /**
     * Remove the specified fuel invoice and its stored image.
     */
    public function destroy(Request $request, FuelInvoice $fuelInvoice): JsonResponse
    {
        if ($fuelInvoice->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        Storage::disk('local')->delete($fuelInvoice->image_path);
        $fuelInvoice->delete();

        return response()->json(['message' => 'Fuel invoice deleted successfully.']);
    }
}
