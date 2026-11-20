<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Services\CustomerBalanceService;
use Illuminate\Contracts\View\View;

/**
 * Printable account statement of a customer (docs/diseno/Impresos2.html).
 */
class CustomerStatementController extends Controller
{
    public function __invoke(Customer $customer, CustomerBalanceService $balances): View
    {
        $customer->load('destinations');

        return view('customers.statement', [
            'customer' => $customer,
            'balance' => $balances->balance($customer),
            'accounts' => $customer->destinations->map(fn (CustomerDestination $destination) => [
                'name' => $destination->name,
                'balance' => $balances->destinationBalance($destination),
            ]),
            'ledger' => $balances->ledger($customer),
        ]);
    }
}
