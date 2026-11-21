<?php

namespace App\Actions\Facturapi;

use App\Models\Invoice;
use App\Models\InvoiceStatus;
use Illuminate\Validation\ValidationException;

class CancelFacturapiInvoice
{
    /** SAT c_MotivoCancelacion. */
    public const MOTIVES = ['01', '02', '03', '04'];

    public function __construct(private FacturapiGateway $gateway) {}

    /**
     * Cancel a stamped invoice before the SAT, recording the motive.
     *
     * @param  string  $motive  SAT c_MotivoCancelacion code: 01 issued with errors and replaced
     *                          (needs the replacing invoice's folio fiscal), 02 with errors without
     *                          replacement (default), 03 operation not carried out, 04 nominative
     *                          operation in a global invoice.
     */
    public function handle(Invoice $invoice, string $motive = '02', ?string $substitutionUuid = null): Invoice
    {
        if (! in_array($motive, self::MOTIVES, true)) {
            throw ValidationException::withMessages(['cancellation_reason' => __('Elige un motivo de cancelación del SAT.')]);
        }

        $substitutionUuid = $motive === '01' ? trim((string) $substitutionUuid) : null;

        if ($motive === '01' && ! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $substitutionUuid)) {
            throw ValidationException::withMessages(['substitution_uuid' => __('Con el motivo 01 escribe el folio fiscal de la factura que la sustituye.')]);
        }

        $this->gateway->cancelInvoice($invoice->facturapi_id, $motive, $substitutionUuid);

        $invoice->status = InvoiceStatus::Canceled;
        $invoice->canceled_at = now();
        $invoice->cancellation_reason = $motive;
        $invoice->substitution_uuid = $substitutionUuid !== null ? mb_strtoupper($substitutionUuid) : null;
        $invoice->save();

        return $invoice->fresh();
    }
}
