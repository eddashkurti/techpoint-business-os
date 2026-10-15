<?php

declare(strict_types=1);

namespace App\Services;

use ZipArchive;

/** Small dependency-free XLSX and PDF writer for tabular operational reports. */
final class ReportDownload
{
    /** @param array<int,array<string,mixed>> $rows @return array{content:string,mime:string,filename:string} */
    public function make(string $name, array $rows, string $format): array
    {
        return match ($format) {
            'xlsx' => ['content' => $this->xlsx($name, $rows), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'filename' => $name.'.xlsx'],
            'pdf' => ['content' => $this->pdf($name, $rows), 'mime' => 'application/pdf', 'filename' => $name.'.pdf'],
            default => ['content' => $this->csv($rows), 'mime' => 'text/csv; charset=UTF-8', 'filename' => $name.'.csv'],
        };
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function csv(array $rows): string
    {
        $out = fopen('php://temp', 'w+b');
        if ($rows) {
            fputcsv($out, array_keys($rows[0]), ',', '"', '');
        }
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($value) => $this->safeValue($value), $row), ',', '"', '');
        }
        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF".$content;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function xlsx(string $name, array $rows): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('Mbështetja ZIP mungon për eksportin XLSX.');
        }
        $headers = $rows ? array_keys($rows[0]) : ['report'];
        $xmlRows = [];
        $xmlRows[] = $this->xlsxRow(1, $headers, true);
        foreach ($rows as $index => $row) {
            $xmlRows[] = $this->xlsxRow($index + 2, array_map(fn ($key) => $row[$key] ?? '', $headers));
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.$this->columns(count($headers)).'</cols><sheetData>'.implode('', $xmlRows).'</sheetData><autoFilter ref="A1:'.$this->cellName(count($headers)).(max(1, count($rows) + 1)).'"/></worksheet>';
        $tmp = tempnam(sys_get_temp_dir(), 'tp-xlsx-');
        if ($tmp === false) {
            throw new \RuntimeException('Nuk mund të krijohet eksporti XLSX.');
        }
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Nuk mund të krijohet arkivi XLSX.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.htmlspecialchars(substr($name, 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="10"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF12395B"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>TechPoint Business OS</dc:creator><dc:title>'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</dc:title><dcterms:created xsi:type="dcterms:W3CDTF">'.now()->utc()->format('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>');
        $zip->close();
        $content = file_get_contents($tmp);
        @unlink($tmp);
        if ($content === false) {
            throw new \RuntimeException('Nuk mund të lexohet eksporti XLSX.');
        }

        return $content;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function pdf(string $name, array $rows): string
    {
        $headers = $rows ? array_keys($rows[0]) : ['report'];
        $lines = [strtoupper(str_replace('-', ' ', $name)), 'Eksportuar: '.now(config('techpoint.timezone'))->format('Y-m-d H:i')];
        $lines[] = implode(' | ', array_map(fn ($header) => str_replace('_', ' ', $header), $headers));
        foreach ($rows as $row) {
            $lines[] = implode(' | ', array_map(fn ($header) => $this->safeValue($row[$header] ?? ''), $headers));
        }
        $pages = array_chunk($lines, 43);
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageIds = [];
        foreach ($pages as $index => $page) {
            $pageIds[] = 4 + ($index * 2);
        }
        $objects[] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($id) => $id.' 0 R', $pageIds)).'] /Count '.count($pageIds).' >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        foreach ($pages as $index => $page) {
            $pageObject = 4 + ($index * 2);
            $contentObject = $pageObject + 1;
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentObject} 0 R >>";
            $commands = ['BT', '/F1 9 Tf', '42 555 Td'];
            foreach ($page as $lineIndex => $line) {
                if ($lineIndex > 0) {
                    $commands[] = '0 -12 Td';
                }
                $commands[] = '('.$this->pdfText($line).') Tj';
            }
            $stream = implode("\n", $commands)."\nET";
            $objects[] = '<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."\nendstream";
        }
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id + 1] = strlen($pdf);
            $pdf .= ($id + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id])."\n";
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n{$xref}\n%%EOF";
    }

    /** @param array<int,string> $values */
    private function xlsxRow(int $number, array $values, bool $header = false): string
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $safe = htmlspecialchars($this->safeValue($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $cells[] = '<c r="'.$this->cellName($index + 1).$number.'" t="inlineStr"'.($header ? ' s="1"' : '').'><is><t xml:space="preserve">'.$safe.'</t></is></c>';
        }

        return '<row r="'.$number.'">'.implode('', $cells).'</row>';
    }

    private function cellName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function columns(int $count): string
    {
        $columns = [];
        for ($i = 1; $i <= $count; $i++) {
            $columns[] = '<col min="'.$i.'" max="'.$i.'" width="18" customWidth="1"/>';
        }

        return implode('', $columns);
    }

    private function safeValue(mixed $value): string
    {
        $string = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);

        return preg_match('/^[=+@\-\t\r]/', $string) ? "'".$string : $string;
    }

    private function pdfText(string $value): string
    {
        $value = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value) ?: $value;
        $value = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);

        return substr($value, 0, 145);
    }
}
