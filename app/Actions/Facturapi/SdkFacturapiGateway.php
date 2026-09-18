<?php

namespace App\Actions\Facturapi;

use Facturapi\Facturapi;

class SdkFacturapiGateway implements FacturapiGateway
{
    public function __construct(private Facturapi $client) {}

    public function createCustomer(array $data): array
    {
        return $this->toArray($this->client->Customers->create($data));
    }

    public function createInvoice(array $data): array
    {
        return $this->toArray($this->client->Invoices->create($data));
    }

    public function cancelInvoice(string $facturapiId, string $motive): array
    {
        return $this->toArray($this->client->Invoices->cancel($facturapiId, ['motive' => $motive]));
    }

    public function downloadPdf(string $facturapiId): string
    {
        return $this->client->Invoices->download_pdf($facturapiId);
    }

    public function downloadXml(string $facturapiId): string
    {
        return $this->client->Invoices->download_xml($facturapiId);
    }

    public function searchProductKeys(string $query): array
    {
        $response = $this->toArray($this->client->Catalogs->searchProducts(['q' => $query]));

        return $this->mapCatalogResults($response);
    }

    public function searchUnitKeys(string $query): array
    {
        $response = $this->toArray($this->client->Catalogs->searchUnits(['q' => $query]));

        return $this->mapCatalogResults($response);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array{key: string, description: string}>
     */
    private function mapCatalogResults(array $response): array
    {
        return collect($response['data'] ?? [])
            ->map(fn (array $item): array => [
                'key' => (string) $item['key'],
                'description' => (string) $item['description'],
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $response): array
    {
        return json_decode(json_encode($response), true) ?? [];
    }
}
