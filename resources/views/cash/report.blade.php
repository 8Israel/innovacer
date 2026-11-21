@php
    $width = setting('ticket_printer_width', '80') === '58' ? '58mm' : '80mm';
    $number = fn ($value) => number_format((float) $value, 2);
    $difference = (string) ($session->cash_difference ?? '0');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('Corte de caja · :date', ['date' => $session->opened_at->format('d/m/Y')]) }}</title>
    <style>
        @page { size: {{ $width }} auto; margin: 3mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Courier New', monospace; font-size: 11px; color: #000; background: #fff; }
        .ticket { width: {{ $width }}; padding: 2mm; margin: 0 auto; }
        .center { text-align: center; }
        .row { display: flex; justify-content: space-between; gap: 4px; }
        .strong { font-weight: 700; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        .actions { padding: 8px; font-family: Arial, sans-serif; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">{{ __('Imprimir corte') }}</button></div>

    <div class="ticket">
        <div class="center strong">{{ setting('business_name', config('app.name')) }}</div>
        <div class="center strong">{{ __('CORTE DE CAJA') }}</div>
        <div class="center">{{ $session->opened_at->translatedFormat('D j M Y') }}</div>
        <hr>
        <div class="row"><span>{{ __('Abrió') }}</span><span>{{ $session->openedBy->name }} {{ $session->opened_at->format('H:i') }}</span></div>
        <div class="row"><span>{{ __('Cerró') }}</span><span>{{ $session->closedBy?->name }} {{ $session->closed_at?->format('H:i') }}</span></div>
        <hr>
        <div class="strong">{{ __('Ventas') }} ({{ $sales['sales_count'] }})</div>
        <div class="row"><span>{{ __('Efectivo') }}</span><span>{{ $number($sales['cash']) }}</span></div>
        <div class="row"><span>{{ __('Tarjeta') }}</span><span>{{ $number($sales['card']) }}</span></div>
        <div class="row"><span>{{ __('Transferencia') }}</span><span>{{ $number($sales['transfer']) }}</span></div>
        <div class="row"><span>{{ __('A crédito') }}</span><span>{{ $number($sales['credit']) }}</span></div>
        <div class="row strong"><span>{{ __('Vendido') }}</span><span>{{ $number($sales['sold']) }}</span></div>
        <hr>
        <div class="strong">{{ __('Efectivo') }}</div>
        <div class="row"><span>{{ __('Fondo inicial') }}</span><span>{{ $number($breakdown['opening_cash']) }}</span></div>
        <div class="row"><span>+ {{ __('Ventas') }}</span><span>{{ $number($breakdown['cash_sales']) }}</span></div>
        <div class="row"><span>+ {{ __('Abonos') }}</span><span>{{ $number($breakdown['cash_customer_payments']) }}</span></div>
        <div class="row"><span>+ {{ __('Entradas') }}</span><span>{{ $number($breakdown['inflows']) }}</span></div>
        <div class="row"><span>− {{ __('Salidas') }}</span><span>{{ $number($breakdown['outflows']) }}</span></div>
        <div class="row"><span>− {{ __('Devoluciones') }}</span><span>{{ $number($breakdown['cash_refunds']) }}</span></div>
        <div class="row"><span>− {{ __('Pagos a proveedores') }}</span><span>{{ $number($breakdown['cash_supplier_payments']) }}</span></div>
        <div class="row strong"><span>{{ __('Debía haber') }}</span><span>{{ $number($session->expected_cash) }}</span></div>
        <div class="row strong"><span>{{ __('Contado') }}</span><span>{{ $number($session->counted_cash) }}</span></div>
        <div class="row strong">
            <span>{{ __('Diferencia') }}</span>
            <span>{{ bccomp($difference, '0', 2) === 0 ? __('Cuadra') : (bccomp($difference, '0', 2) > 0 ? __('Sobran').' '.$number($difference) : __('Faltan').' '.$number(abs((float) $difference))) }}</span>
        </div>
        <div class="row"><span>{{ __('Fondo que se deja') }}</span><span>{{ $number($session->cash_left) }}</span></div>
        @if ($movements->isNotEmpty())
            <hr>
            <div class="strong">{{ __('Entradas y salidas') }}</div>
            @foreach ($movements as $movement)
                <div class="row"><span>{{ $movement->occurred_at->format('H:i') }} {{ $movement->concept }}</span><span>{{ $movement->type === \App\Models\CashMovementType::Outflow ? '−' : '+' }}{{ $number($movement->amount) }}</span></div>
            @endforeach
        @endif
        @if ($session->closing_note)
            <hr>
            <div>{{ __('Nota: :note', ['note' => $session->closing_note]) }}</div>
        @endif
        <hr>
        <br>
        <div class="center">______________________</div>
        <div class="center">{{ __('Firma de quien entrega') }}</div>
    </div>
</body>
</html>
