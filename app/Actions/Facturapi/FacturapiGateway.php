<?php

namespace App\Actions\Facturapi;

interface FacturapiGateway
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createCustomer(array $data): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createInvoice(array $data): array;

    /**
     * @return array<string, mixed>
     */
    public function cancelInvoice(string $facturapiId, string $motive): array;

    public function downloadPdf(string $facturapiId): string;

    public function downloadXml(string $facturapiId): string;

    /**
     * Search the SAT product/service catalog (c_ClaveProdServ).
     *
     * @return array<int, array{key: string, description: string}>
     */
    public function searchProductKeys(string $query): array;

    /**
     * Search the SAT unit of measure catalog (c_ClaveUnidad).
     *
     * @return array<int, array{key: string, description: string}>
     */
    public function searchUnitKeys(string $query): array;
}
