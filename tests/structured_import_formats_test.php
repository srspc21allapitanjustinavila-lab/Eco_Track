<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/waste_analytics.php';

function checkStructuredImport($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

$uploadPage = file_get_contents(__DIR__ . '/../waste_import.php');
$helpersStart = strpos($uploadPage, 'function normalizeImportWasteAmount');
$requestHandlerStart = strpos($uploadPage, "\n// Handle the first file and create its reviewable preview.", $helpersStart);
checkStructuredImport($helpersStart !== false && $requestHandlerStart !== false, 'Structured-import helpers are available for testing.');
eval(substr($uploadPage, $helpersStart, $requestHandlerStart - $helpersStart));

$jsonPath = tempnam(sys_get_temp_dir(), 'ecotrack-json-');
file_put_contents($jsonPath, json_encode(['records' => [[
    'Bioman' => 'Collector One', 'Street' => 'Acacia St.', 'Households' => 12,
    'Collection Date' => '2025-04-01', 'Factory Returnable' => 6, 'Unrelated Note' => 'ignore this',
]]], JSON_UNESCAPED_UNICODE));
[$jsonHeaders, $jsonRows] = parseJsonUpload($jsonPath);
@unlink($jsonPath);
checkStructuredImport(!empty($jsonHeaders) && count($jsonRows) === 1 && $jsonRows[0][1] === 'Acacia St.', 'JSON record arrays normalize through the existing Waste Data header pipeline.');

if (class_exists('ZipArchive')) {
    $docxPath = tempnam(sys_get_temp_dir(), 'ecotrack-docx-');
    $zip = new ZipArchive();
    $zip->open($docxPath, ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Bioman</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Street</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Collection Date</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Residual Waste</w:t></w:r></w:p></w:tc></w:tr><w:tr><w:tc><w:p><w:r><w:t>Collector One</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Birch St.</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>2025-04-02</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>9</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:body></w:document>');
    $zip->close();
    [$docxHeaders, $docxRows] = parseDocxUpload($docxPath);
    @unlink($docxPath);
    checkStructuredImport(!empty($docxHeaders) && count($docxRows) === 1 && $docxRows[0][1] === 'Birch St.', 'DOCX table rows normalize through the existing Waste Data header pipeline.');
}

checkStructuredImport(wasteImportAuditFormat('waste.json') === 'json' && wasteImportAuditFormat('waste.docx') === 'docx', 'Structured file formats are retained in the import audit.');
