<?php

namespace App\Actions\Facturapi;

use App\Models\Invoice;
use App\Models\InvoiceStatus;

class CancelFacturapiInvoice
{
    public function __construct(private FacturapiGateway $gateway) {}

    /**
     * Cancel a stamped invoice before the SAT.
     *
     * @param  string  $motive  SAT c_MotivoCancelacion code (defaults to "02": issued with errors, no relation).
     */
    public function handle(Invoice $invoice, string $motive = '02'): Invoice
    {
        $this->gateway->cancelInvoice($invoice->facturapi_id, $motive);

        $invoice->status = InvoiceStatus::Canceled;
        $invoice->canceled_at = now();
        $invoice->save();

        return $invoice->fresh();
    }
}
