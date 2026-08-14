<?php

namespace App\Services;

use App\Models\ReportOutput;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ReportExportService
{
    public function write(ReportOutput $output, array $result, string $title, string $definitionKey, int $version): ReportOutput
    {
        $format = $output->format;
        $columns = array_values(array_filter($result['columns'] ?? [], fn ($column) => ($column['visible'] ?? true) === true));
        $rows = $result['rows'] ?? [];
        $metadata = $result['meta'] ?? [];
        $filename = Str::slug($definitionKey).'-'.now()->format('YmdHis').'.'.$format;
        [$bytes, $contentType] = match ($format) {
            'csv' => [$this->csv($columns, $rows, $metadata), 'text/csv; charset=UTF-8'],
            'xlsx' => [$this->xlsx($columns, $rows, $metadata, $title), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'pdf' => [$this->pdf($columns, $rows, $metadata, $title), 'application/pdf'],
            default => ['', 'application/json'],
        };
        $path = 'reports/'.$output->company_id.'/'.$output->id.'/'.$filename;
        Storage::disk('local')->put($path, $bytes);
        $output->update(['storage_disk' => 'local', 'storage_path' => $path, 'content_type' => $contentType, 'size_bytes' => strlen($bytes), 'result_meta' => array_merge($metadata, ['filename' => $filename, 'definition_key' => $definitionKey, 'definition_version' => $version, 'durability' => 'transient_local_storage'])]);

        return $output->refresh();
    }

    private function csv(array $columns, array $rows, array $metadata): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['SimpleBIZ Report']);
        foreach (['definition_key', 'definition_version', 'freshness_state', 'source_as_of_at', 'as_of_date'] as $key) {
            if (array_key_exists($key, $metadata)) {
                fputcsv($stream, [$key, $this->safeValue($metadata[$key])]);
            }
        }
        fputcsv($stream, array_map(fn ($column) => $column['label'] ?? $column['key'], $columns));
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($column) => $this->safeValue($this->value($row, $column['key'] ?? '')), $columns));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF".$csv;
    }

    private function xlsx(array $columns, array $rows, array $metadata, string $title): string
    {
        $sheetRows = [['SimpleBIZ Report', '', '', '']];
        foreach (['definition_key', 'definition_version', 'freshness_state', 'source_as_of_at', 'as_of_date'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $sheetRows[] = [$key, $this->safeValue($metadata[$key]), '', ''];
            }
        }
        $sheetRows[] = array_map(fn ($column) => $column['label'] ?? $column['key'], $columns);
        foreach ($rows as $row) {
            $sheetRows[] = array_map(fn ($column) => $this->safeValue($this->value($row, $column['key'] ?? '')), $columns);
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($sheetRows as $rowIndex => $row) {
            $sheet .= '<row r="'.($rowIndex + 1).'">';
            foreach (array_values($row) as $columnIndex => $value) {
                $ref = $this->columnName($columnIndex + 1).($rowIndex + 1);
                if (is_numeric($value) && $value !== '') {
                    $sheet .= '<c r="'.$ref.'"><v>'.htmlspecialchars((string) $value, ENT_XML1).'</v></c>';
                } else {
                    $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.htmlspecialchars(Str::limit($title, 31, ''), ENT_XML1).'" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
        $zip = new \ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'simplebiz-report-');
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $content);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function pdf(array $columns, array $rows, array $metadata, string $title): string
    {
        $lines = [$title, 'Generated by SimpleBIZ', 'Freshness: '.($metadata['freshness_state'] ?? 'unknown')];
        $lines[] = implode(' | ', array_map(fn ($column) => $column['label'] ?? $column['key'], $columns));
        foreach (array_slice($rows, 0, 40) as $row) {
            $lines[] = implode(' | ', array_map(fn ($column) => $this->safeValue($this->value($row, $column['key'] ?? '')), $columns));
        }
        $stream = "BT\n/F1 9 Tf\n40 780 Td\n12 TL\n";
        foreach ($lines as $line) {
            $stream .= '('.$this->pdfEscape(Str::limit($line, 150)).") Tj T*\n";
        }
        $stream .= "ET\n";
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', 3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', 5 => '<< /Length '.strlen($stream).' >>\nstream\n'.$stream.'endstream'];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($number = 1; $number <= count($objects); $number++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$number])."\n";
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    private function value(array $row, string $key): mixed
    {
        foreach (explode('.', $key) as $part) {
            if (! is_array($row) || ! array_key_exists($part, $row)) {
                return '';
            } $row = $row[$part];
        }

        return $row;
    }

    private function safeValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $value = (string) $value;

        return in_array($value[0] ?? '', ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $name = chr(65 + $remainder).$name;
            $number = intdiv($number - 1, 26);
        }

        return $name;
    }

    private function pdfEscape(string $value): string
    {
        $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
