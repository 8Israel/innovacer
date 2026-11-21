<?php

use App\Models\Warehouse;
use App\Services\ProductImportService;
use App\Services\XlsxFile;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Importar productos')] class extends Component {
    use WithFileUploads;

    public $file = null;

    public ?int $warehouse_id = null;

    /** @var list<list<string>> rows of the uploaded sheet, headers first. */
    public array $sheet = [];

    public string $file_name = '';

    public string $sheet_name = '';

    public bool $only_problems = false;

    /** @var array{products: int, with_stock: int, skipped: int}|null */
    public ?array $result = null;

    public function mount(): void
    {
        $this->warehouse_id = $this->warehouses->firstWhere('is_sellable', true)?->id ?? $this->warehouses->first()?->id;
    }

    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->where('is_active', true)->orderByDesc('is_sellable')->orderBy('name')->get();
    }

    /**
     * @return array{missing_columns: list<string>, rows: list<array{line: int, data: array<string, string>, errors: list<string>, warnings: list<string>}>}
     */
    #[Computed]
    public function analysis(): array
    {
        return app(ProductImportService::class)->analyze($this->sheet);
    }

    public function downloadTemplate(ProductImportService $import): StreamedResponse
    {
        return response()->streamDownload(fn () => print($import->template()), 'plantilla-productos.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function updatedFile(XlsxFile $xlsx): void
    {
        $this->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'],
        ], attributes: ['file' => __('archivo')]);

        $read = $xlsx->read($this->file->getRealPath(), $this->file->getClientOriginalName(), 'Productos');

        $this->sheet = $read['rows'];
        $this->sheet_name = $read['sheet'];
        $this->file_name = $this->file->getClientOriginalName();
        $this->result = null;
        $this->reset('file', 'only_problems');
        unset($this->analysis);
    }

    public function changeFile(): void
    {
        $this->reset('sheet', 'sheet_name', 'file_name', 'only_problems', 'result');
        unset($this->analysis);
    }

    public function import(ProductImportService $import): void
    {
        $this->validate(['warehouse_id' => ['required', 'integer', 'exists:warehouses,id']]);

        $this->result = $import->import(Auth::user(), Warehouse::query()->findOrFail($this->warehouse_id), $this->sheet, $this->file_name);
        $this->reset('sheet', 'sheet_name', 'only_problems');
        unset($this->analysis);

        Flux::toast(variant: 'success', text: trans_choice(':count producto importado.|:count productos importados.', $this->result['products']));
    }
}; ?>

@php
    $analysis = $this->analysis;
    $rows = collect($analysis['rows']);
    $withErrors = $rows->filter(fn ($row) => $row['errors'] !== []);
    $withWarnings = $rows->filter(fn ($row) => $row['errors'] === [] && $row['warnings'] !== []);
    $ready = $rows->count() - $withErrors->count() - $withWarnings->count();
    $visible = $only_problems ? $rows->filter(fn ($row) => $row['errors'] !== [] || $row['warnings'] !== []) : $rows;
    $toImport = $rows->count() - $withErrors->count();
    $step = $sheet === [] ? 1 : 2;
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div>
        <flux:link :href="route('inventory.index')" wire:navigate class="text-sm">← {{ __('Inventario') }}</flux:link>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <flux:heading size="xl">{{ __('Importar productos desde Excel') }}</flux:heading>
            <flux:button icon="arrow-down-tray" wire:click="downloadTemplate" data-test="download-template-button">{{ __('Descargar plantilla (.xlsx)') }}</flux:button>
        </div>
    </div>

    <ol class="flex flex-wrap gap-4 text-sm">
        @foreach ([1 => __('Subir archivo'), 2 => __('Revisar'), 3 => __('Confirmar')] as $number => $label)
            <li class="flex items-center gap-2 {{ $number === $step ? 'font-semibold text-ink dark:text-white' : 'text-zinc-500' }}">
                <span class="flex size-6 items-center justify-center rounded-full border {{ $number <= $step ? 'border-brand bg-brand text-white' : 'border-line dark:border-zinc-600' }}">{{ $number }}</span>
                {{ $label }}
            </li>
        @endforeach
    </ol>

    @if ($result)
        <flux:callout variant="success" icon="check-circle" :heading="trans_choice(':count producto importado|:count productos importados', $result['products'])">
            <flux:callout.text>
                {{ trans_choice(':count con existencia inicial en el kardex|:count con existencia inicial en el kardex', $result['with_stock']) }}
                @if ($result['skipped'] > 0)
                    · {{ trans_choice(':count fila omitida por error|:count filas omitidas por error', $result['skipped']) }}
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($step === 1)
        <flux:card class="flex flex-col gap-4">
            <flux:text>{{ __('Llena la plantilla (una fila por producto) y súbela. Solo la clave y el nombre son obligatorios; lo demás se puede completar después.') }}</flux:text>
            <flux:select wire:model="warehouse_id" :label="__('Existencia inicial va a')">
                @foreach ($this->warehouses as $warehouse)
                    <flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="file" wire:model="file" :label="__('Archivo (.xlsx o .csv)')" accept=".xlsx,.csv" />
            <div wire:loading wire:target="file"><flux:text size="sm">{{ __('Leyendo el archivo…') }}</flux:text></div>
            <flux:error name="file" />
        </flux:card>
    @else
        <flux:card class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="font-medium">{{ $file_name }}</div>
                <flux:text size="sm">{{ trans_choice(':count fila|:count filas', $rows->count()) }} · {{ __('hoja «:sheet»', ['sheet' => $sheet_name]) }}</flux:text>
            </div>
            <div class="w-64">
                <flux:select wire:model="warehouse_id" :label="__('Existencia inicial va a')">
                    @foreach ($this->warehouses as $warehouse)
                        <flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:button wire:click="changeFile">{{ __('Cambiar archivo') }}</flux:button>
        </flux:card>

        @if ($analysis['missing_columns'] !== [])
            <flux:callout variant="danger" icon="x-circle" :heading="__('Al archivo le faltan columnas')">
                <flux:callout.text>{{ __('No encontré: :columns. Usa la plantilla para que los encabezados coincidan.', ['columns' => implode(', ', $analysis['missing_columns'])]) }}</flux:callout.text>
            </flux:callout>
        @else
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:card><flux:heading size="lg">{{ $ready }}</flux:heading><flux:text size="sm">{{ __('listas para importar') }}</flux:text></flux:card>
                <flux:card><flux:heading size="lg">{{ $withWarnings->count() }}</flux:heading><flux:text size="sm">{{ __('con advertencia, se importan') }}</flux:text></flux:card>
                <flux:card><flux:heading size="lg">{{ $withErrors->count() }}</flux:heading><flux:text size="sm">{{ __('con error, se omiten') }}</flux:text></flux:card>
            </div>

            <flux:radio.group wire:model.live="only_problems" variant="segmented" size="sm">
                <flux:radio :value="false" :label="__('Todas (:count)', ['count' => $rows->count()])" />
                <flux:radio :value="true" :label="__('Con problemas (:count)', ['count' => $withErrors->count() + $withWarnings->count()])" />
            </flux:radio.group>

            <flux:card class="p-0">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Fila') }}</flux:table.column>
                        <flux:table.column>{{ __('Clave') }}</flux:table.column>
                        <flux:table.column>{{ __('Nombre') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Precio') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Existencia') }}</flux:table.column>
                        <flux:table.column>{{ __('Resultado') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($visible as $row)
                            <flux:table.row :key="'line-'.$row['line']">
                                <flux:table.cell class="tabular-nums">{{ $row['line'] }}</flux:table.cell>
                                <flux:table.cell>{{ $row['data']['sku'] ?: '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $row['data']['name'] ?: '—' }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ is_numeric(str_replace([',', '$'], '', $row['data']['unit_price'])) ? '$'.number_format((float) str_replace([',', '$'], '', $row['data']['unit_price']), 2) : __('vacío') }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $row['data']['stock'] ?: '0' }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($row['errors'] !== [])
                                        <flux:badge size="sm" color="red">{{ __('Error') }}</flux:badge>
                                        <span class="text-sm">{{ implode(' ', $row['errors']) }}</span>
                                    @elseif ($row['warnings'] !== [])
                                        <flux:badge size="sm" color="amber">{{ __('Advertencia') }}</flux:badge>
                                        <span class="text-sm">{{ implode(' ', $row['warnings']) }}</span>
                                    @else
                                        <flux:badge size="sm" color="green">{{ __('Lista') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <div class="flex flex-wrap justify-end gap-2">
                <flux:button wire:click="changeFile">{{ __('Corregir y volver a subir') }}</flux:button>
                <flux:button variant="primary" wire:click="import" :disabled="$toImport === 0" data-test="import-button">
                    {{ trans_choice('Importar :count producto|Importar :count productos', $toImport) }}
                </flux:button>
            </div>
        @endif
    @endif
</div>
