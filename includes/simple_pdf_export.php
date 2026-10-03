<?php

// Small dependency-free PDF exporter for tabular EcoTrack downloads.
// It uses the PDF base fonts so a separate library is not required.

function simplePdfText($value)
{
    $text = trim((string)$value);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
}

function simplePdfShorten($value, $limit)
{
    $value = trim((string)$value);
    return strlen($value) > $limit ? substr($value, 0, max(1, $limit - 3)) . '...' : $value;
}

function simplePdfLine($text, $x, $y, $size = 8, $bold = false)
{
    $font = $bold ? 'F2' : 'F1';
    return "BT /{$font} {$size} Tf 1 0 0 1 {$x} {$y} Tm (" . simplePdfText($text) . ") Tj ET\n";
}

function buildSimpleTablePdf($title, $subtitle, array $headers, array $rows)
{
    $pageWidth = 842;
    $pageHeight = 595;
    $left = 32;
    $right = 32;
    $tableWidth = $pageWidth - $left - $right;
    $columnCount = max(1, count($headers));
    $columnWidth = $tableWidth / $columnCount;
    $headerLimit = max(7, (int)floor($columnWidth / 4.4));
    $valueLimit = max(8, (int)floor($columnWidth / 4.9));
    $rowsPerPage = 31;
    $chunks = array_chunk($rows, $rowsPerPage);
    if (empty($chunks)) {
        $chunks = [[]];
    }

    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $pageObjectNumbers = [];

    foreach ($chunks as $pageIndex => $pageRows) {
        $content = "0.06 0.30 0.26 rg\n";
        $content .= simplePdfLine($title, $left, 558, 16, true);
        $content .= "0.25 0.32 0.30 rg\n";
        $content .= simplePdfLine($subtitle, $left, 542, 8);
        $content .= simplePdfLine('Generated: ' . date('Y-m-d H:i:s') . '  |  Page ' . ($pageIndex + 1) . ' of ' . count($chunks), $left, 529, 7);
        $content .= "0.84 0.90 0.87 rg\n{$left} 508 {$tableWidth} 16 re f\n0.08 0.16 0.14 rg\n";
        foreach ($headers as $index => $header) {
            $content .= simplePdfLine(simplePdfShorten($header, $headerLimit), round($left + ($index * $columnWidth) + 3), 513, 6.5, true);
        }
        $y = 497;
        foreach ($pageRows as $row) {
            $content .= "0.88 0.91 0.89 RG 0.4 w {$left} " . ($y - 3) . " {$tableWidth} 14 re S\n0.10 0.15 0.13 rg\n";
            foreach ($headers as $index => $_header) {
                $value = $row[$index] ?? '';
                $content .= simplePdfLine(simplePdfShorten($value, $valueLimit), round($left + ($index * $columnWidth) + 3), $y + 1, 6.5);
            }
            $y -= 15;
        }
        if (empty($pageRows)) {
            $content .= simplePdfLine('No matching records were found.', $left, 489, 9);
        }

        $contentObject = count($objects) + 1;
        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $pageObject = count($objects) + 1;
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentObject} 0 R >>";
        $pageObjectNumbers[] = $pageObject;
    }

    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static function ($number) {
        return $number . ' 0 R';
    }, $pageObjectNumbers)) . '] /Count ' . count($pageObjectNumbers) . ' >>';
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $number => $object) {
        $offsets[$number + 1] = strlen($pdf);
        $pdf .= ($number + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
    }
    $pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    return $pdf;
}

function streamSimpleTablePdf($filename, $title, $subtitle, array $headers, array $rows)
{
    $pdf = buildSimpleTablePdf($title, $subtitle, $headers, $rows);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
