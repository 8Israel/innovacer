<?php

namespace App\Http\Controllers;

use App\Actions\Facturapi\FacturapiGateway;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InvoiceDownloadController extends Controller
{
    public function pdf(Invoice $invoice, FacturapiGateway $gateway): HttpResponse
    {
        abort_unless($invoice->status === InvoiceStatus::Stamped && $invoice->facturapi_id, 404);

        return response($gateway->downloadPdf($invoice->facturapi_id))
            ->header('Content-Type', 'application/pdf');
    }

    public function xml(Invoice $invoice, FacturapiGateway $gateway): HttpResponse
    {
        abort_unless($invoice->status === InvoiceStatus::Stamped && $invoice->facturapi_id, 404);

        return response($gateway->downloadXml($invoice->facturapi_id))
            ->header('Content-Type', 'application/xml');
    }
}
