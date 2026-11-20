<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\AmountInWords;
use Illuminate\Contracts\View\View;

/**
 * Printable documents of a charged sale (docs/diseno/Ticket.html): the thermal
 * ticket and the formal sale note, both with the business data from settings.
 */
class SaleDocumentController extends Controller
{
    public function ticket(Sale $sale): View
    {
        return view('sales.ticket', ['sale' => $this->load($sale)]);
    }

    public function note(Sale $sale, AmountInWords $amountInWords): View
    {
        return view('sales.note', [
            'sale' => $this->load($sale),
            'totalInWords' => $amountInWords->pesos($sale->total),
        ]);
    }

    private function load(Sale $sale): Sale
    {
        abort_if($sale->isPending(), 404);

        return $sale->load(['items.product', 'payments', 'customer', 'customerDestination', 'user']);
    }
}
