<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * قراءة وكتابة CSV و XLSX بدون مكتبات خارجية.
 */
final class Spreadsheet
{
    /** قراءة ملف إلى مصفوفة صفوف (كل صف مصفوفة نصوص) */
    public static function read(string $path, string $originalName = ''): array
    {
        $ext = strtolower(pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
        $head = (string) file_get_contents($path, false, null, 0, 4);
        if ($ext === 'xlsx' || str_starts_with($head, "PK\x03\x04")) {
            return self::readXlsx($path);
        }
        if ($ext === 'xls') {
            throw new RuntimeException('صيغة XLS القديمة غير مدعومة. احفظ الملف بصيغة XLSX أو CSV.');
        }
        return self::readDelimited((string) file_get_contents($path));
    }

    /** نص مفصول (CSV/TXT/لصق) مع اكتشاف الفاصل والترميز */
    public static function readDelimited(string $text): array
    {
        $text = self::toUtf8($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_values(array_filter(explode("\n", $text), static fn ($l) => trim($l) !== ''));
        if (!$lines) {
            return [];
        }
        $sample = array_slice($lines, 0, 20);
        $delimiter = null;
        $best = 0;
        foreach (["\t", ',', ';', '|'] as $d) {
            $counts = array_map(static fn ($l) => substr_count($l, $d), $sample);
            $min = min($counts);
            if ($min > 0 && $min >= $best) {
                $best = $min;
                $delimiter = $d;
            }
        }
        $rows = [];
        if ($delimiter !== null) {
            $fh = fopen('php://temp', 'r+');
            fwrite($fh, implode("\n", $lines));
            rewind($fh);
            while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
                $rows[] = array_map(static fn ($v) => trim((string) $v), $row);
            }
            fclose($fh);
        } else {
            // سطر بقيم مفصولة بمسافات أو نقطتين أو شرطة مائلة
            foreach ($lines as $l) {
                $rows[] = array_values(array_filter(preg_split('/[\s:\/]+/u', trim($l)), static fn ($v) => $v !== ''));
            }
        }
        return $rows;
    }

    public static function toUtf8(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            return substr($text, 3);
        }
        if (str_starts_with($text, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($text, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE');
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1256');
        }
        return $text;
    }

    public static function readXlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('قراءة ملفات Excel تتطلب إضافة Zip في PHP. استخدم ملف CSV.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('تعذّر فتح ملف Excel.');
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $xml = self::xml($ss);
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } else {
                    $s = '';
                    foreach ($si->r as $r) {
                        $s .= (string) $r->t;
                    }
                    $shared[] = $s;
                }
            }
        }
        // أول ورقة في المصنف
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $wbx = self::xml($wb);
            $relx = self::xml($rels);
            $first = $wbx->sheets->sheet[0] ?? null;
            if ($first) {
                $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($relx->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }
        $sheet = $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheet === false) {
            throw new RuntimeException('لم يتم العثور على ورقة بيانات في الملف.');
        }
        $xml = self::xml($sheet);
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::columnIndex(preg_replace('/\d+/', '', $ref));
                $type = (string) $c['t'];
                if ($type === 's') {
                    $val = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string) ($c->is->t ?? '');
                } elseif ($type === 'b') {
                    $val = (string) $c->v === '1' ? 'TRUE' : 'FALSE';
                } else {
                    $val = self::number((string) $c->v);
                }
                $cells[$col] = trim($val);
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $line = [];
                for ($i = 0; $i <= $max; $i++) {
                    $line[] = $cells[$i] ?? '';
                }
                if (implode('', $line) !== '') {
                    $rows[] = $line;
                }
            }
        }
        return $rows;
    }

    private static function xml(string $content): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new RuntimeException('محتوى ملف Excel غير صالح.');
        }
        return $xml;
    }

    /** الأرقام الكبيرة تُخزَّن أحياناً بصيغة علمية؛ تُعاد كأرقام كاملة */
    private static function number(string $v): string
    {
        if (preg_match('/^-?\d+\.0+$/', $v)) {
            return substr($v, 0, strpos($v, '.'));
        }
        if (preg_match('/^-?\d+(\.\d+)?E\+?\d+$/i', $v)) {
            return number_format((float) $v, 0, '.', '');
        }
        return $v;
    }

    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    // ------------------------------------------------------------------
    // الكتابة
    // ------------------------------------------------------------------

    /** إزالة رموز العزل الاتجاهي غير المرئية من القيم المصدّرة */
    public static function plain(string $v): string
    {
        return str_replace(["\u{2066}", "\u{2069}"], '', $v);
    }

    /** CSV مع BOM ليفتح في Excel بالعربية بشكل صحيح */
    public static function csv(array $header, iterable $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header, ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($fh, array_map(static fn ($v) => $v === null ? '' : self::plain((string) $v), $r), ',', '"', '\\');
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    /** ملف XLSX بورقة واحدة (كل القيم نصوص للحفاظ على الأصفار البادئة) */
    public static function xlsx(array $header, iterable $rows, string $sheetName = 'Sheet1', bool $rtl = true): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('إنشاء ملفات Excel يتطلب إضافة Zip في PHP.');
        }
        $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = static function (int $i): string {
            $s = '';
            $i++;
            while ($i > 0) {
                $m = ($i - 1) % 26;
                $s = chr(65 + $m) . $s;
                $i = intdiv($i - 1, 26);
            }
            return $s;
        };
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"' . ($rtl ? ' rightToLeft="1"' : '') . '><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>';
        foreach ($header as $i => $_) {
            $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="20" customWidth="1"/>';
        }
        $sheet .= '</cols><sheetData>';
        $r = 1;
        $line = '<row r="1">';
        foreach (array_values($header) as $i => $h) {
            $line .= '<c r="' . $col($i) . '1" t="inlineStr" s="1"><is><t>' . $esc($h) . '</t></is></c>';
        }
        $sheet .= $line . '</row>';
        foreach ($rows as $row) {
            $r++;
            $line = '<row r="' . $r . '">';
            foreach (array_values($row) as $i => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                if (is_int($v) || is_float($v)) {
                    $line .= '<c r="' . $col($i) . $r . '"><v>' . $v . '</v></c>';
                } else {
                    $line .= '<c r="' . $col($i) . $r . '" t="inlineStr"><is><t xml:space="preserve">' . $esc(self::plain((string) $v)) . '</t></is></c>';
                }
            }
            $sheet .= $line . '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $tmp = temp_file('xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE9EFFD"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }
}
