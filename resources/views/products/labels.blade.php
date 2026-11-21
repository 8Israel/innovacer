@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('Etiquetas') }}</title>
    <style>
        @if ($format['sheet'])
            @page { size: letter; margin: 12.7mm 4.8mm; }
        @else
            @page { size: {{ $format['width'] }} {{ $format['height'] }}; margin: 0; }
        @endif
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'IBM Plex Sans', Arial, sans-serif; color: #000; background: #fff; }
        .sheet { display: {{ $format['sheet'] ? 'grid' : 'block' }}; grid-template-columns: repeat(3, {{ $format['width'] }}); }
        .label { width: {{ $format['width'] }}; height: {{ $format['height'] }}; padding: 1.5mm 2mm; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; {{ $format['sheet'] ? '' : 'page-break-after: always; break-after: page;' }} }
        .top { display: flex; justify-content: space-between; gap: 2mm; font-size: 7pt; line-height: 1.15; }
        .name { font-weight: 600; max-height: 2.3em; overflow: hidden; }
        .price { font-size: 9pt; font-weight: 700; white-space: nowrap; }
        .bars { height: 13mm; }
        .bars svg { width: 100%; height: 100%; }
        .no-code { font-size: 7pt; border: 1px dashed #999; padding: 1mm; text-align: center; }
        .sku { font-size: 6pt; color: #333; }
        .actions { padding: 8px; font-family: Arial, sans-serif; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('Imprimir etiquetas') }}</button>
        <span>{{ trans_choice(':count etiqueta|:count etiquetas', count($labels)) }} · {{ $format['label'] }}</span>
    </div>

    <div class="sheet">
        @foreach ($labels as $label)
            <div class="label">
                <div class="top">
                    <span class="name">{{ $label['product']->name }}</span>
                    @if ($label['price'])
                        <span class="price">{{ $money($label['price']) }}</span>
                    @endif
                </div>
                @if ($label['svg'])
                    <div class="bars">{!! $label['svg'] !!}</div>
                @else
                    <div class="no-code">{{ __('Sin código EAN-13: genera un código interno') }}</div>
                @endif
                <div class="sku">{{ $label['product']->sku }}</div>
            </div>
        @endforeach
    </div>
</body>
</html>
