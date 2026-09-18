<?php

namespace App\Actions\Facturapi;

use App\Models\Customer;

class SyncFacturapiCustomer
{
    public function __construct(private FacturapiGateway $gateway) {}

    /**
     * Ensure the customer exists in Facturapi and return its remote ID.
     */
    public function handle(Customer $customer): string
    {
        if ($customer->facturapi_id) {
            return $customer->facturapi_id;
        }

        $response = $this->gateway->createCustomer([
            'legal_name' => $customer->name,
            'tax_id' => $customer->rfc,
            'tax_system' => $customer->tax_system,
            'email' => $customer->email,
            'address' => ['zip' => $customer->zip_code],
        ]);

        $customer->facturapi_id = $response['id'];
        $customer->save();

        return $customer->facturapi_id;
    }
}
