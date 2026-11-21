<?php

namespace App\Http\Controllers;

use App\Concerns\RoundsDecimals;
use App\Models\Product;
use App\Services\Ean13;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Printable barcode labels (RF-07): one per copy asked, in the size of the label
 * roll or a letter sheet. The price shown is what the customer pays, taxes included.
 */
class ProductLabelController extends Controller
{
    use RoundsDecimals;

    public const FORMATS = [
        '50x25' => ['label' => 'Rollo 50 × 25 mm', 'width' => '50mm', 'height' => '25mm', 'sheet' => false],
        '38x25' => ['label' => 'Rollo 38 × 25 mm', 'width' => '38mm', 'height' => '25mm', 'sheet' => false],
        'carta' => ['label' => 'Hoja carta (3 × 10)', 'width' => '66.7mm', 'height' => '25.4mm', 'sheet' => true],
    ];

    public function __invoke(Request $request, Ean13 $ean13): View
    {
        $validated = $request->validate([
            'p' => ['required', 'array', 'max:200'],
            'p.*' => ['integer', 'min:1', 'max:500'],
            'formato' => ['nullable', 'in:'.implode(',', array_keys(self::FORMATS))],
        ]);

        $products = Product::query()->whereKey(array_keys($validated['p']))->get()->keyBy('id');
        $labels = [];

        foreach ($validated['p'] as $productId => $copies) {
            $product = $products->get($productId);

            if ($product === null) {
                continue;
            }

            $code = $ean13->normalize($product->barcode);
            $label = [
                'product' => $product,
                'svg' => $code ? $ean13->svg($code) : null,
                'price' => $this->priceWithTaxes($product),
            ];

            array_push($labels, ...array_fill(0, (int) $copies, $label));
        }

        return view('products.labels', [
            'labels' => $labels,
            'format' => self::FORMATS[$validated['formato'] ?? '50x25'],
        ]);
    }

    private function priceWithTaxes(Product $product): ?string
    {
        if ($product->unit_price === null) {
            return null;
        }

        $withIeps = bcmul($product->unit_price, bcadd('1', $product->ieps_rate ?? '0', 4), 8);

        return $this->roundHalfUp(bcmul($withIeps, bcadd('1', $product->tax_rate ?? '0', 4), 8), 2);
    }
}
