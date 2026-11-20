@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('Estado de cuenta · :customer', ['customer' => $customer->name]) }}</title>
    <style>
        @page { size: letter; margin: 15mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'IBM Plex Sans', Arial, sans-serif; font-size: 12px; color: #15211B; background: #fff; }
        .page { max-width: 190mm; margin: 0 auto; padding: 10mm 0; }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #125D40; padding-bottom: 12px; }
        header img { height: 56px; }
        h1 { font-family: Montserrat, Arial, sans-serif; color: #125D40; font-size: 20px; margin: 0; text-align: end; }
        .muted { color: #56635B; }
        .boxes { display: flex; gap: 12px; margin: 16px 0; flex-wrap: wrap; }
        .box { border: 1px solid #DDE3DD; border-radius: 6px; padding: 8px 12px; min-width: 40mm; }
        .label { font-size: 10px; text-transform: uppercase; color: #56635B; }
        .amount { font-size: 15px; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { text-align: start; font-size: 10px; text-transform: uppercase; color: #56635B; border-bottom: 1px solid #DDE3DD; padding: 6px 4px; }
        td { border-bottom: 1px solid #DDE3DD; padding: 6px 4px; }
        .num { text-align: end; font-variant-numeric: tabular-nums; }
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
                <div class="muted">{{ collect([setting('business_address'), setting('business_phone') ? __('Tel.').' '.setting('business_phone') : null])->filter()->join(' · ') }}</div>
            </div>
            <div>
                <h1>{{ __('ESTADO DE CUENTA') }}</h1>
                <div class="muted" style="text-align: end">{{ __('Al :date', ['date' => now()->translatedFormat('j \d\e F \d\e Y')]) }}</div>
            </div>
        </header>

        <div class="boxes">
            <div class="box"><div class="label">{{ __('Cliente') }}</div>{{ $customer->name }}</div>
            <div class="box"><div class="label">{{ __('Saldo total') }}</div><div class="amount">{{ $money($balance) }}</div></div>
            @foreach ($accounts as $account)
                <div class="box"><div class="label">{{ __('Cuenta :name', ['name' => $account['name']]) }}</div><div class="amount">{{ $money($account['balance']) }}</div></div>
            @endforeach
        </div>

        <table>
            <thead>
                <tr>
                    <th>{{ __('Fecha') }}</th>
                    <th>{{ __('Concepto') }}</th>
                    <th>{{ __('Cuenta') }}</th>
                    <th class="num">{{ __('Cargo') }}</th>
                    <th class="num">{{ __('Abono') }}</th>
                    <th class="num">{{ __('Saldo') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($ledger as $entry)
                    <tr>
                        <td>{{ $entry['date']->translatedFormat('j M') }}</td>
                        <td>{{ $entry['concept'] }}</td>
                        <td>{{ $entry['account'] ?? '—' }}</td>
                        <td class="num">{{ (float) $entry['charge'] > 0 ? $money($entry['charge']) : '' }}</td>
                        <td class="num">{{ (float) $entry['payment'] > 0 ? $money($entry['payment']) : '' }}</td>
                        <td class="num">{{ $money($entry['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('Sin movimientos.') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        <footer class="muted">
            {{ __('El saldo de cada renglón es el de su cuenta.') }}
            @if (setting('business_phone'))
                {{ __('Para aclaraciones comuníquese al :phone.', ['phone' => setting('business_phone')]) }}
            @endif
        </footer>
    </div>
</body>
</html>
