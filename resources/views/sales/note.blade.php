@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    $paid = $sale->payments->reduce(fn ($carry, $payment) => bcadd($carry, $payment->amount, 2), '0');
    $paymentForms = $sale->payments->map(fn ($payment) => $payment->method->label())->unique()->values();
    if (bccomp(bcsub($sale->total, $paid, 2), '0', 2) === 1) {
        $paymentForms->push(__('A crédito'));
    }
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('Nota de venta :folio', ['folio' => $sale->folio]) }}</title>
    <style>
        @page { size: letter; margin: 15mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'IBM Plex Sans', Arial, sans-serif; font-size: 12px; color: #15211B; background: #fff; }
        .page { max-width: 190mm; margin: 0 auto; padding: 10mm 0; }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #125D40; padding-bottom: 12px; }
        header img { height: 56px; }
        h1 { font-family: Montserrat, Arial, sans-serif; color: #125D40; font-size: 20px; margin: 0; text-align: end; }
        .muted { color: #56635B; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px 16px; margin: 16px 0; }
        .label { font-size: 10px; text-transform: uppercase; color: #56635B; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { text-align: start; font-size: 10px; text-transform: uppercase; color: #56635B; border-bottom: 1px solid #DDE3DD; padding: 6px 4px; }
        td { border-bottom: 1px solid #DDE3DD; padding: 6px 4px; }
        .num { text-align: end; font-variant-numeric: tabular-nums; }
        .totals { margin-top: 12px; margin-inline-start: auto; width: 45%; }
        .totals .row { display: flex; justify-content: space-between; padding: 3px 0; }
        .totals .grand { font-size: 15px; font-weight: 600; border-top: 1px solid #15211B; margin-top: 4px; padding-top: 6px; }
        .signature { margin-top: 48px; width: 45%; border-top: 1px solid #15211B; text-align: center; padding-top: 4px; }
        footer { margin-top: 24px; font-size: 10px; }
        .actions { padding: 8px; }
        @media print { .actions { display: none; } .page { padding: 0; } }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('Imprimir o guardar como PDF') }}</button>
    </div>

    <div class="page">
        <header>
            <div>
                <img src="{{ asset('images/logo-horizontal.png') }}" alt="{{ setting('business_name', config('app.name')) }}">
                <div class="muted">
                    {{ collect([setting('business_address'), setting('business_phone') ? __('Tel.').' '.setting('business_phone') : null])->filter()->join(' · ') }}
                </div>
                @if (setting('business_rfc'))
                    <div class="muted">{{ __('RFC') }} {{ setting('business_rfc') }}</div>
                @endif
            </div>
            <div>
                <h1>{{ __('NOTA DE VENTA') }}</h1>
                <div class="muted" style="text-align: end">{{ __('Folio') }} {{ $sale->folio }}</div>
                <div class="muted" style="text-align: end">{{ $sale->sold_at->translatedFormat('j \d\e F \d\e Y') }}</div>
                @if ($sale->isCanceled())
                    <div style="text-align: end; color: #9B2C1F; font-weight: 600">{{ __('CANCELADA') }}</div>
                @endif
            </div>
        </header>

        <div class="grid">
            <div><div class="label">{{ __('Cliente') }}</div>{{ $sale->customer?->name ?? __('Público en general') }}</div>
            <div><div class="label">{{ __('Cuenta o destino') }}</div>{{ $sale->customerDestination?->name ?? '—' }}</div>
            <div><div class="label">{{ __('Forma de pago') }}</div>{{ $paymentForms->join(', ') }}</div>
            <div><div class="label">{{ __('Atendió') }}</div>{{ $sale->user->name }}</div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>{{ __('Clave') }}</th>
                    <th>{{ __('Descripción') }}</th>
                    <th class="num">{{ __('Cant.') }}</th>
                    <th class="num">{{ __('Precio') }}</th>
                    <th class="num">{{ __('Importe') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sale->items as $item)
                    <tr>
                        <td>{{ $item->product->sku }}</td>
                        <td>{{ $item->product->name }}</td>
                        <td class="num">{{ $quantity($item->quantity) }}</td>
                        <td class="num">{{ $money($item->unit_price) }}</td>
                        <td class="num">{{ $money($item->subtotal) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($sale->notes)
            <p><span class="label">{{ __('Observaciones') }}</span><br>{{ $sale->notes }}</p>
        @endif

        <div class="totals">
            <div class="row"><span>{{ __('Subtotal') }}</span><span class="num">{{ $money($sale->subtotal) }}</span></div>
            @if (bccomp($sale->discount_total, '0', 2) === 1)
                <div class="row"><span>{{ __('Descuento') }}</span><span class="num">−{{ $money($sale->discount_total) }}</span></div>
            @endif
            @if (bccomp($sale->tax_total, '0', 2) === 1)
                <div class="row"><span>{{ __('IVA') }}</span><span class="num">{{ $money($sale->tax_total) }}</span></div>
            @endif
            @if (bccomp($sale->ieps_total, '0', 2) === 1)
                <div class="row"><span>{{ __('IEPS') }}</span><span class="num">{{ $money($sale->ieps_total) }}</span></div>
            @endif
            <div class="row grand"><span>{{ __('Total') }}</span><span class="num">{{ $money($sale->total) }}</span></div>
        </div>

        <p class="muted">{{ $totalInWords }}</p>

        <div class="signature">{{ __('Recibí de conformidad') }}</div>

        <footer class="muted">{{ __('Este documento no es un comprobante fiscal. Si requiere factura, solicítela con su folio.') }}</footer>
    </div>
</body>
</html>
