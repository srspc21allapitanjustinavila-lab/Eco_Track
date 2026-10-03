<?php

// Browser regression fixture for the real DSS filter markup and client code.
$source = file_get_contents(__DIR__ . '/../collection_schedule.php');
$html = substr($source, strpos($source, '<!DOCTYPE html>'));

$html = preg_replace_callback('/<\?php(.*?)\?>/s', static function ($match) {
    if (strpos($match[1], '$dssCalendarYearsJson') !== false) {
        return json_encode([2026, 2025, 2024]);
    }
    if (strpos($match[1], 'foreach') !== false) {
        return '';
    }
    return '';
}, $html);

$html = preg_replace(
    '#(<input[^>]+name="mode"[^>]+value="daily")[^>]*>#',
    '$1 checked>',
    $html,
    1
);

$setup = '<base href="../"><script src="tests/dss_filter_fixture.browser.js"></script>';
$html = str_replace('<head>', '<head>' . $setup, $html);
echo $html;
