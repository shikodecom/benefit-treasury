<?php

namespace App\Services\Imports;

use Illuminate\Validation\ValidationException;
use ZipArchive;

class SafeXlsxReader
{
    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'XLSXを読み取れません。']);
        }
        try {
            if ($zip->numFiles > 500) {
                $this->invalid();
            }
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $size += $stat['size'];
                if ($size > 80 * 1024 * 1024 || str_contains($stat['name'], '..') || str_starts_with($stat['name'], '/')) {
                    $this->invalid();
                }
                if (preg_match('~(^|/)(vbaProject|externalLinks|connections)~i', $stat['name'])) {
                    $this->invalid();
                }
            }
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $shared = $this->xml($zip, 'xl/sharedStrings.xml');
                foreach ($shared->xpath('//*[local-name()="si"]') ?: [] as $si) {
                    $strings[] = implode('', $si->xpath('.//*[local-name()="t"]') ? array_map(fn ($t) => (string) $t, $si->xpath('.//*[local-name()="t"]')) : []);
                }
            }
            $targets = [];
            foreach ($rels->Relationship as $rel) {
                $targets[(string) $rel['Id']] = (string) $rel['Target'];
            }
            $result = [];
            $sheets = $workbook->xpath('//*[local-name()="sheet"]') ?: [];
            if (count($sheets) > 30) {
                $this->invalid();
            }
            foreach ($sheets as $sheet) {
                $name = (string) $sheet['name'];
                $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $target = $targets[(string) $attrs['id']] ?? null;
                if (! $target) {
                    $this->invalid();
                }
                $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.ltrim($target, '/');
                $sheetXml = $this->xml($zip, $sheetPath);
                $rows = [];
                foreach ($sheetXml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
                    if (count($rows) >= 20000) {
                        $this->invalid();
                    }
                    $values = [];
                    foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                        if ($cell->xpath('./*[local-name()="f"]')) {
                            continue;
                        }
                        $column = preg_replace('/\d+/', '', (string) $cell['r']);
                        $type = (string) $cell['t'];
                        $value = $type === 'inlineStr' ? implode('', array_map(fn ($t) => (string) $t, $cell->xpath('.//*[local-name()="t"]') ?: [])) : (string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '');
                        if ($type === 's') {
                            $value = $strings[(int) $value] ?? '';
                        }
                        if ($value !== '') {
                            $values[$column] = $value;
                        }
                    }
                    if ($values) {
                        $rows[(int) $row['r']] = $values;
                    }
                }
                $result[$name] = $rows;
            }

            return $result;
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $name): \SimpleXMLElement
    {
        $content = $zip->getFromName($name);
        if ($content === false || strlen($content) > 30 * 1024 * 1024 || str_contains($content, '<!DOCTYPE')) {
            $this->invalid();
        }
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if (! $xml) {
            $this->invalid();
        }

        return $xml;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['file' => '安全に解析できないXLSXです。形式とサイズを確認してください。']);
    }
}
