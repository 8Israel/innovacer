@php
    $width = setting('ticket_printer_width', '80') === '58' ? '58mm' : '80mm';
    $number = fn ($value) => number_format((float) $value, 2);
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    $paid = $sale->payments->reduce(fn ($carry, $payment) => bcadd($carry, $payment->amount, 2), '0');
    $onCredit = bcsub($sale->total, $paid, 2);
    $change = $sale->payments->reduce(fn ($carry, $payment) => bcadd($carry, $payment->change_amount ?? '0', 2), '0');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('Ticket :folio', ['folio' => $sale->folio]) }}</title>
    <style>
        @page { size: {{ $width }} auto; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Courier New', monospace; font-size: 12px; color: #000; background: #fff; }
        .ticket { width: {{ $width }}; padding: 4mm 3mm; }
        .center { text-align: center; }
        .row { display: flex; justify-content: space-between; gap: 4px; }
        .line { border-top: 1px dashed #000; margin: 6px 0; }
        .bold { font-weight: bold; }
        .total { font-size: 15px; font-weight: bold; }
        .actions { padding: 8px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('Imprimir') }}</button>
    </div>

    <div class="ticket">
        <div class="center bold">{{ setting('business_name', config('app.name')) }}</div>
        @if (setting('business_address'))
            <div class="center">{{ setting('business_address') }}</div>
        @endif
        @if (setting('business_phone'))
            <div class="center">{{ __('Tel.') }} {{ setting('business_phone') }}</div>
        @endif
        @if (setting('business_rfc'))
            <div class="center">{{ __('RFC') }} {{ setting('business_rfc') }}</div>
        @endif

        <div class="line"></div>

        <div>{{ __('Venta') }} {{ $sale->folio }}</div>
        @if ($sale->isCanceled())
            <div class="bold">{{ __('VENTA CANCELADA') }}</div>
        @endif
        <div>{{ $sale->sold_at->format('d/m/Y H:i') }}</div>
        <div>{{ __('Atendió:') }} {{ $sale->user->name }}</div>
        <div>{{ __('Cliente:') }} {{ $sale->customer?->name ?? __('Público en general') }}</div>
        @if ($sale->customerDestination)
            <div>{{ __('Destino:') }} {{ $sale->customerDestination->name }}</div>
        @endif

        <div class="line"></div>

        @foreach ($sale->items as $item)
            <div>{{ mb_strtoupper($item->product->name) }}</div>
            <div class="row">
                <span>{{ $quantity($item->quantity) }} x {{ $number($item->unit_price) }}</span>
                <span>{{ $number($item->subtotal) }}</span>
            </div>
        @endforeach

        <div class="line"></div>

        <div class="row"><span>{{ __('Subtotal') }}</span><span>{{ $number($sale->subtotal) }}</span></div>
        @if (bccomp($sale->discount_total, '0', 2) === 1)
            <div class="row"><span>{{ __('Descuento') }}</span><span>-{{ $number($sale->discount_total) }}</span></div>
        @endif
        @if (bccomp(bcadd($sale->tax_total, $sale->ieps_total, 2), '0', 2) === 1)
            <div class="row"><span>{{ __('IVA / IEPS') }}</span><span>{{ $number(bcadd($sale->tax_total, $sale->ieps_total, 2)) }}</span></div>
        @endif
        <div class="row total"><span>{{ __('TOTAL') }}</span><span>${{ $number($sale->total) }}</span></div>

        @foreach ($sale->payments as $payment)
            <div class="row">
                <span>{{ $payment->method->label() }}</span>
                <span>{{ $number($payment->received_amount ?? $payment->amount) }}</span>
            </div>
        @endforeach
        @if (bccomp($change, '0', 2) === 1)
            <div class="row"><span>{{ __('Cambio') }}</span><span>{{ $number($change) }}</span></div>
        @endif
        @if (bccomp($onCredit, '0', 2) === 1)
            <div class="row"><span>{{ __('A crédito') }}</span><span>{{ $number($onCredit) }}</span></div>
        @endif

        <div class="line"></div>

        <div class="center">{{ trans_choice(':count pieza|:count piezas', (int) $sale->items->sum(fn ($item) => (float) $item->quantity)) }} · {{ __('Gracias por su compra') }}</div>
        <div class="center">{{ __('Este ticket no es comprobante fiscal') }}</div>
    </div>
</body>
</html>
