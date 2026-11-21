<?php

namespace App\Actions\Facturapi;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceStatus;
use App\Models\Product;
use Facturapi\Exceptions\Facturapi_Exception;

class StampInvoice
{
    public function __construct(
        private FacturapiGateway $gateway,
        private SyncFacturapiCustomer $syncCustomer,
    ) {}

    /**
     * Send a draft invoice to Facturapi to be stamped (timbrada) by the SAT.
     * Every product needs its SAT product and unit keys; otherwise the invoice
     * is marked as failed without calling Facturapi.
     */
    public function handle(Invoice $invoice): Invoice
    {
        $invoice->loadMissing(['customer', 'items.product']);

        $missingSatKeys = $invoice->items
            ->map(fn (InvoiceItem $item) => $item->product)
            ->filter(fn (Product $product) => blank($product->product_key) || blank($product->unit_key))
            ->pluck('name')
            ->unique();

        if ($missingSatKeys->isNotEmpty()) {
            $invoice->status = InvoiceStatus::Failed;
            $invoice->error_message = __('Falta la clave de producto o de unidad SAT de: :products.', [
                'products' => $missingSatKeys->join(', '),
            ]);
            $invoice->save();

            return $invoice->fresh();
        }

        try {
            $facturapiCustomerId = $this->syncCustomer->handle($invoice->customer);

            $response = $this->gateway->createInvoice([
                'customer' => $facturapiCustomerId,
                'items' => $invoice->items->map(fn (InvoiceItem $item): array => [
                    'quantity' => (float) $item->quantity,
                    'product' => [
                        'description' => $item->product->name,
                        'product_key' => $item->product->product_key,
                        'price' => (float) $item->unit_price,
                        'unit_key' => $item->product->unit_key,
                        'unit_name' => $item->product->unit_name,
                        'tax_included' => false,
                        'taxes' => [['type' => 'IVA', 'rate' => (float) $item->tax_rate]],
                    ],
                ])->values()->all(),
                'payment_form' => $invoice->payment_form,
                'payment_method' => $invoice->payment_method,
                'use' => $invoice->cfdi_use,
                'currency' => $invoice->currency,
            ]);

            $invoice->facturapi_id = $response['id'];
            $invoice->uuid = $response['uuid'] ?? null;
            $invoice->status = InvoiceStatus::Stamped;
            $invoice->series = $response['series'] ?? null;
            $invoice->folio = isset($response['folio_number']) ? (string) $response['folio_number'] : null;
            $invoice->stamped_at = now();
            $invoice->error_message = null;
        } catch (Facturapi_Exception $e) {
            $invoice->status = InvoiceStatus::Failed;
            $invoice->error_message = $e->getMessage();
        }

        $invoice->save();

        return $invoice->fresh();
    }
}
