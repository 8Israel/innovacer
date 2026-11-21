<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use XMLReader;
use ZipArchive;

/**
 * The little of the .xlsx format the product import needs, without extra packages:
 * reading the cell values of one sheet and writing a one-sheet workbook. CSV files
 * (what Excel saves as "CSV UTF-8") are read too.
 */
class XlsxFile
{
    /**
     * Rows of the sheet with the given name, or of the first sheet, as lists of trimmed strings.
     *
     * @return array{sheet: string, rows: list<list<string>>}
     *
     * @throws ValidationException
     */
    public function read(string $path, string $originalName, ?string $preferredSheet = null): array
    {
        if (str_ends_with(mb_strtolower($originalName), '.csv')) {
            return ['sheet' => pathinfo($originalName, PATHINFO_FILENAME), 'rows' => $this->readCsv($path)];
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true || $zip->locateName('xl/workbook.xml') === false) {
            throw ValidationException::withMessages(['file' => __('El archivo no es un Excel (.xlsx) válido.')]);
        }

        try {
            $sheets = $this->sheets($zip);
            $sheet = collect($sheets)->first(fn (array $sheet) => $preferredSheet !== null && mb_strtolower($sheet['name']) === mb_strtolower($preferredSheet)) ?? $sheets[0]
                ?? throw ValidationException::withMessages(['file' => __('El archivo no tiene hojas.')]);

            $xml = $zip->getFromName($sheet['path']);

            if ($xml === false) {
                throw ValidationException::withMessages(['file' => __('No se pudo leer la hoja «:sheet».', ['sheet' => $sheet['name']])]);
            }

            return ['sheet' => $sheet['name'], 'rows' => $this->readSheet($xml, $this->sharedStrings($zip))];
        } finally {
            $zip->close();
        }
    }

    /**
     * A workbook with one sheet; every cell is written as text so codes keep their leading zeros.
     *
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function write(string $sheetName, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escape($sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs>'
            .'</styleSheet>');

        $sheetRows = '';

        foreach (array_values($rows) as $rowIndex => $row) {
            $cells = '';

            foreach (array_values($row) as $columnIndex => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $reference = $this->columnName($columnIndex).($rowIndex + 1);
                $style = $rowIndex === 0 ? ' s="1"' : '';
                $cells .= '<c r="'.$reference.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.$this->escape((string) $value).'</t></is></c>';
            }

            $sheetRows .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();

        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    private function sheets(ZipArchive $zip): array
    {
        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $relations = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        $targets = [];

        foreach ($relations->Relationship ?? [] as $relation) {
            $target = ltrim((string) $relation['Target'], '/');
            $targets[(string) $relation['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        $sheets = [];

        foreach ($workbook->sheets->sheet ?? [] as $sheet) {
            $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

            if (isset($targets[$id])) {
                $sheets[] = ['name' => (string) $sheet['name'], 'path' => $targets[$id]];
            }
        }

        return $sheets;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $strings = [];

        foreach (simplexml_load_string($xml)->si ?? [] as $item) {
            $strings[] = isset($item->t) ? (string) $item->t : implode('', array_map(fn ($run) => (string) $run->t, iterator_to_array($item->r, false)));
        }

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<list<string>>
     */
    private function readSheet(string $xml, array $sharedStrings): array
    {
        $reader = XMLReader::XML($xml);
        $rows = [];
        $row = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
                $row = [];
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'row') {
                $rows[] = $this->fill($row ?? []);
                $row = null;
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'c' && $row !== null) {
                $column = $this->columnIndex((string) $reader->getAttribute('r'));
                $type = (string) $reader->getAttribute('t');
                $cell = simplexml_load_string($reader->readOuterXml());
                $value = match ($type) {
                    's' => $sharedStrings[(int) $cell->v] ?? '',
                    'inlineStr' => (string) ($cell->is->t ?? implode('', array_map(fn ($run) => (string) $run->t, iterator_to_array($cell->is->r ?? [], false)))),
                    'b' => (string) $cell->v === '1' ? '1' : '0',
                    default => (string) $cell->v,
                };
                $row[$column] = trim($value);
            }
        }

        $reader->close();

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($value) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $value)), $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, string>  $cells
     * @return list<string>
     */
    private function fill(array $cells): array
    {
        if ($cells === []) {
            return [];
        }

        $row = array_fill(0, max(array_keys($cells)) + 1, '');

        foreach ($cells as $index => $value) {
            $row[$index] = $value;
        }

        return $row;
    }

    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/\d+/', '', $reference);
        $index = 0;

        foreach (str_split(strtoupper((string) $letters)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function columnName(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
