<?php

require_once 'config.php';
requireUserType('admin');

header('Content-Type: application/json; charset=utf-8');

function explanationResponse($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function explanationKg($value)
{
    return number_format((float)$value, 2) . ' kg';
}

function explanationPercentChange($from, $to)
{
    $from = (float)$from;
    $to = (float)$to;
    if ($from <= 0) {
        return null;
    }
    return round((($to - $from) / $from) * 100);
}

function explanationPercentOfTotal($value, $total)
{
    $total = (float)$total;
    if ($total <= 0) {
        return 0;
    }
    return round(((float)$value / $total) * 100);
}

function explanationDifference($first, $second)
{
    return abs((float)$first - (float)$second);
}

function generateRuleBasedExplanation($chartId, $data, $language = 'en')
{
    $data = is_array($data) ? $data : [];
    $isTagalog = $language === 'tl';

    if ($chartId === 'total-waste') {
        $total = (float)($data['total_waste_kg'] ?? 0);
        $records = (int)($data['total_records'] ?? 0);
        return $records > 0
            ? ($isTagalog
                ? 'May kabuuang ' . explanationKg($total) . ' na naitalang basura mula sa ' . number_format($records) . ' waste entries. Katumbas ito ng average na ' . explanationKg($total / $records) . ' bawat entry, kaya mas malinaw ang lawak ng kabuuang volume sa dashboard.'
                : 'A total of ' . explanationKg($total) . ' has been recorded across ' . number_format($records) . ' waste entries. That is an average of ' . explanationKg($total / $records) . ' per entry, which gives context to the overall volume shown on the dashboard.')
            : ($isTagalog ? 'Wala pang waste records, kaya ang kabuuang nakolektang basura ay 0.00 kg.' : 'There are no waste records yet, so the total collected waste is 0.00 kg.');
    }

    if ($chartId === 'diversion-rate') {
        $rate = (float)($data['diversion_rate_percent'] ?? 0);
        $diverted = (float)($data['diverted_kg'] ?? 0);
        $remaining = (float)($data['residual_kg'] ?? 0) + (float)($data['hazardous_kg'] ?? 0);
        return $isTagalog
            ? 'Ang diversion rate ay ' . number_format($rate, 0) . '%: ' . explanationKg($diverted) . ' ang returnable o biowaste. Ang natitirang ' . explanationKg($remaining) . ' (' . number_format(max(0, 100 - $rate), 0) . '%) ay residual o hazardous waste, kaya ipinapakita ng chart kung gaano karami ang maaaring ma-divert.'
            : 'The diversion rate is ' . number_format($rate, 0) . '%: ' . explanationKg($diverted) . ' is classified as returnable or biowaste. The remaining ' . explanationKg($remaining) . ' (' . number_format(max(0, 100 - $rate), 0) . '%) is residual or hazardous waste, so the chart is weighted toward material that can be diverted.';
    }

    if ($chartId === 'highest-household') {
        $phaseName = trim((string)($data['phase_name'] ?? 'No phase data'));
        $street = trim((string)($data['street'] ?? 'No establishment data'));
        $total = (float)($data['total_waste_kg'] ?? 0);
        $records = (int)($data['record_count'] ?? 0);
        $period = trim((string)($data['period_label'] ?? 'all uploaded reporting periods'));
        $rows = is_array($data['areas'] ?? null) ? $data['areas'] : [];
        $second = $rows[1] ?? null;
        $comparison = '';
        if ($second) {
            $secondTotal = (float)($second['total_waste'] ?? 0);
            $comparison = ' It is ahead of the next area by ' . explanationKg(explanationDifference($total, $secondTotal)) . '.';
        }
        if ($isTagalog) {
            $tagalogComparison = $second ? ' Mas mataas ito nang ' . explanationKg(explanationDifference($total, $second['total_waste'] ?? 0)) . ' kaysa sa susunod na area.' : '';
            return $total > 0
                ? $street . ' sa ' . $phaseName . ' ang may pinakamataas na naitalang basura na ' . explanationKg($total) . ' para sa ' . $period . '.' . $tagalogComparison . ' Niraranggo nito ang bawat Collection Group + Area ayon sa pinakamataas nitong uploaded record.'
                : 'Hindi pa sapat ang waste records para maipakita ang pinakamataas na waste record.';
        }
        return $total > 0
            ? $street . ' in ' . $phaseName . ' has the highest recorded waste at ' . explanationKg($total) . ' for ' . $period . '.' . $comparison . ' This ranks each Collection Group + Area by its single highest uploaded record.'
            : 'There are not enough waste records yet to show the highest waste record.';
    }

    if ($chartId === 'records-collectors') {
        $records = (int)($data['total_records'] ?? 0);
        $collectors = (int)($data['active_collectors'] ?? 0);
        $average = $collectors > 0 ? ' That is about ' . number_format($records / $collectors, 1) . ' record' . ($records / $collectors == 1 ? '' : 's') . ' per collector.' : ' No active collectors are currently listed.';
        if ($isTagalog) {
            $tagalogAverage = $collectors > 0 ? ' Katumbas ito ng humigit-kumulang ' . number_format($records / $collectors, 1) . ' record bawat collector.' : ' Wala pang aktibong collector na nakalista.';
            return $records > 0 ? 'May ' . number_format($records) . ' waste records ang dashboard mula sa ' . number_format($collectors) . ' aktibong collector.' . $tagalogAverage . ' Ipinapakita nito ang saklaw ng naitalang collection activity.' : 'Wala pang waste records o aktibong collector sa dashboard.';
        }
        return $records > 0 ? 'The dashboard contains ' . number_format($records) . ' waste records from ' . number_format($collectors) . ' active collector' . ($collectors === 1 ? '' : 's') . '.' . $average . ' This indicates the recorded coverage of collection activity.' : 'There are no waste records or active collectors in the dashboard yet.';
    }

    if ($chartId === 'waste-household') {
        $rows = is_array($data['areas'] ?? null) ? $data['areas'] : [];
        if (!$rows) {
            return $isTagalog ? 'Wala pang waste records na maikukumpara sa mga Collection Group at Area.' : 'There are no waste records yet. Add waste records to compare Phase and Street areas.';
        }
        usort($rows, function ($a, $b) {
            return (float)($b['total_waste'] ?? 0) <=> (float)($a['total_waste'] ?? 0);
        });
        $top = $rows[0];
        $second = $rows[1] ?? null;
        $topTotal = (float)($top['total_waste'] ?? 0);
        $lead = $second ? ' It leads the next area by ' . explanationKg(explanationDifference($topTotal, $second['total_waste'] ?? 0)) . '.' : '';
        if ($isTagalog) {
            $tagalogLead = $second ? ' Mas mataas ito nang ' . explanationKg(explanationDifference($topTotal, $second['total_waste'] ?? 0)) . ' kaysa sa susunod na area.' : '';
            return ($top['street'] ?? 'Ang nangungunang area') . ' sa ' . ($top['phase_name'] ?? 'collection group nito') . ' ang may pinakamataas na naitalang basura na ' . explanationKg($topTotal) . '.' . $tagalogLead . ' Inihahambing ng chart ang bawat Collection Group + Area gamit ang pinakamataas nitong uploaded record, mula pinakamataas hanggang pinakamababa.';
        }
        return ($top['street'] ?? 'The top establishment') . ' in ' . ($top['phase_name'] ?? 'its collection group') . ' has the highest recorded waste at ' . explanationKg($topTotal) . '.' . $lead . ' The chart compares each Collection Group + Area using its single highest uploaded record, ranked from highest to lowest.';
    }

    if ($chartId === 'category-breakdown') {
        $categories = [
            'Factory Returnable' => (float)($data['factory_returnable_kg'] ?? 0),
            'Biowaste' => (float)($data['biowaste_kg'] ?? 0),
            'Residual' => (float)($data['residual_kg'] ?? 0),
            'Hazardous' => (float)($data['hazardous_kg'] ?? 0),
        ];
        arsort($categories);
        $names = array_keys($categories);
        $topName = $names[0];
        $topValue = $categories[$topName];
        $total = array_sum($categories);
        $rate = (float)($data['diversion_rate_percent'] ?? 0);
        if ($total <= 0) {
            return $isTagalog ? 'Wala pang category data. Magdagdag ng waste records na may waste category amounts para makita ang breakdown.' : 'There is no category data yet. Add waste records with category amounts to show this breakdown.';
        }
        if ($isTagalog) {
            return $topName . ' ang pinakamalaking category na may ' . explanationKg($topValue) . ', o ' . number_format(explanationPercentOfTotal($topValue, $total), 0) . '% ng kabuuang ' . explanationKg($total) . '. Sumusunod ang ' . $names[1] . ' na may ' . explanationKg($categories[$names[1]]) . ', na may pagitan na ' . explanationKg(explanationDifference($topValue, $categories[$names[1]])) . '. Magkasama, ang returnable at biowaste ay ' . number_format($rate, 0) . '% ng lahat ng naitalang basura.';
        }
        return $topName . ' is the largest category at ' . explanationKg($topValue) . ', representing ' . number_format(explanationPercentOfTotal($topValue, $total), 0) . '% of the ' . explanationKg($total) . ' total. ' . $names[1] . ' follows at ' . explanationKg($categories[$names[1]]) . ', a gap of ' . explanationKg(explanationDifference($topValue, $categories[$names[1]])) . '. Together, returnable and biowaste account for ' . number_format($rate, 0) . '% of all recorded waste.';
    }

    if ($chartId === 'collection-trend') {
        $rows = is_array($data['periods'] ?? null) ? $data['periods'] : [];
        if (!$rows) {
            return $isTagalog ? 'Wala pang collection trend data. Magdagdag ng waste records na may dates para makita ang chart.' : 'There is no collection trend data yet. Add waste records with dates to show this chart.';
        }
        $first = $rows[0];
        $last = $rows[count($rows) - 1];
        $change = explanationPercentChange($first['total_waste'] ?? 0, $last['total_waste'] ?? 0);
        $values = array_map(function ($row) {
            return (float)($row['total_waste'] ?? 0);
        }, $rows);
        $peakIndex = array_keys($values, max($values))[0];
        $peak = $rows[$peakIndex];
        $trend = $change === null ? 'There is no starting total to compare.' : 'This is a ' . number_format(abs($change)) . '% ' . ($change >= 0 ? 'increase' : 'decrease') . ' from the first period.';
        if ($isTagalog) {
            $tagalogTrend = $change === null ? 'Walang panimulang total na maikukumpara.' : 'Ito ay ' . number_format(abs($change)) . '% na ' . ($change >= 0 ? 'pagtaas' : 'pagbaba') . ' mula sa unang period.';
            return 'Ang pinakahuling collection date (' . ($last['date'] ?? 'pinakahuling collection date') . ') ay may ' . explanationKg($last['total_waste'] ?? 0) . '. ' . $tagalogTrend . ' Ang pinakamataas na punto ay ' . explanationKg($peak['total_waste'] ?? 0) . ' noong ' . ($peak['date'] ?? 'peak collection date') . '.';
        }
        return 'The latest collection date (' . ($last['date'] ?? 'latest collection date') . ') records ' . explanationKg($last['total_waste'] ?? 0) . '. ' . $trend . ' The highest point is ' . explanationKg($peak['total_waste'] ?? 0) . ' on ' . ($peak['date'] ?? 'the peak collection date') . '.';
    }

    if ($chartId === 'collector-comparison') {
        $rows = is_array($data['collectors'] ?? null) ? $data['collectors'] : [];
        if (!$rows) {
            return $isTagalog ? 'Wala pang collector data. Magdagdag ng collector names sa waste records para makita ang chart.' : 'There is no collector data yet. Add collector names to waste records to show this chart.';
        }
        usort($rows, function ($a, $b) {
            return (float)($b['total_waste'] ?? 0) <=> (float)($a['total_waste'] ?? 0);
        });
        $top = $rows[0];
        $second = $rows[1] ?? null;
        $total = array_sum(array_map(function ($row) {
            return (float)($row['total_waste'] ?? 0);
        }, $rows));
        $topTotal = (float)($top['total_waste'] ?? 0);
        $secondText = $second ? ' The next highest is ' . ($second['garbage_collector'] ?? 'the next collector') . ' at ' . explanationKg($second['total_waste'] ?? 0) . ', a difference of ' . explanationKg(explanationDifference($topTotal, $second['total_waste'] ?? 0)) . '.' : '';
        if ($isTagalog) {
            $tagalogSecondText = $second ? ' Ang susunod ay si ' . ($second['garbage_collector'] ?? 'ang susunod na collector') . ' na may ' . explanationKg($second['total_waste'] ?? 0) . ', na may pagitan na ' . explanationKg(explanationDifference($topTotal, $second['total_waste'] ?? 0)) . '.' : '';
            return ($top['garbage_collector'] ?? 'Ang nangungunang collector') . ' ang may pinakamataas na naitalang koleksyon na ' . explanationKg($topTotal) . ', o ' . number_format(explanationPercentOfTotal($topTotal, $total), 0) . '% ng kinumparang total.' . $tagalogSecondText . ' Ipinapakita nito kung paano nahahati ang naitalang collection volume sa mga collector.';
        }
        return ($top['garbage_collector'] ?? 'The top collector') . ' has the highest recorded collection at ' . explanationKg($topTotal) . ', representing ' . number_format(explanationPercentOfTotal($topTotal, $total), 0) . '% of the compared total.' . $secondText . ' This comparison shows how recorded collection volume is distributed among collectors.';
    }

    return $isTagalog ? 'Wala pang available na insight para sa dashboard card na ito.' : 'No explanation is available for this dashboard card yet.';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    explanationResponse(405, ['error' => 'POST requests only.']);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['dashboard_explanation_csrf']) || !hash_equals($_SESSION['dashboard_explanation_csrf'], $csrfToken)) {
    explanationResponse(403, ['error' => 'Your dashboard session has expired. Refresh the page and try again.']);
}

$request = json_decode(file_get_contents('php://input'), true);
if (!is_array($request) || !isset($request['id'], $request['data'])) {
    explanationResponse(400, ['error' => 'Invalid explanation request.']);
}

$allowedCharts = [
    'total-waste' => 'Total Waste Collected',
    'diversion-rate' => 'Waste Diversion Rate',
    'highest-household' => 'Highest Waste Record',
    'records-collectors' => 'Records And Collectors',
    'waste-household' => 'Waste by Collection Group / Area',
    'category-breakdown' => 'Waste Category Breakdown',
    'collection-trend' => 'Waste Collection Trend',
    'collector-comparison' => 'Collector Comparison',
];
$chartId = (string)$request['id'];
if (!isset($allowedCharts[$chartId])) {
    explanationResponse(400, ['error' => 'Unknown dashboard card.']);
}
$language = ($request['language'] ?? 'en') === 'tl' ? 'tl' : 'en';

// The trend card already calculates its exact week-to-week movement on the
// server. Return that shared interpretation directly so an AI response cannot
// change the weekly totals or invent a reason for the movement.
if ($chartId === 'collection-trend') {
    $sharedDescription = trim((string)($request['data'][$language === 'tl' ? 'trend_description_tl' : 'trend_description'] ?? ''));
    if ($sharedDescription !== '') {
        explanationResponse(200, ['explanation' => $sharedDescription, 'source' => 'shared_weekly_trend']);
    }
}

$dataJson = json_encode($request['data'], JSON_UNESCAPED_SLASHES);
if ($dataJson === false || strlen($dataJson) > 16000) {
    explanationResponse(400, ['error' => 'Dashboard data is invalid or too large to explain.']);
}

$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey || !function_exists('curl_init')) {
    explanationResponse(200, [
        'explanation' => generateRuleBasedExplanation($chartId, $request['data'], $language),
        'source' => 'rule_based_fallback',
    ]);
}
if (!function_exists('curl_init')) {
    explanationResponse(500, ['error' => 'PHP cURL is required to generate AI explanations.']);
}

$languageInstruction = $language === 'tl'
    ? 'Write in clear Filipino (Tagalog). Keep place names, names, kg, percentages, and figures unchanged.'
    : 'Write in clear English.';
$payload = [
    'model' => getenv('OPENAI_MODEL') ?: 'gpt-4o-mini',
    'temperature' => 0.3,
    'max_tokens' => 220,
    'messages' => [
        [
            'role' => 'system',
            'content' => 'You explain EcoTrack waste dashboard cards for an administrator. Use only the supplied JSON values. Write 2 or 3 concise, natural sentences. ' . $languageInstruction . ' Make the insight useful, not generic: state the main value, then add the strongest available comparison (share of total, gap to second place, change from first to latest period, or highest period). Explain what the comparison means for the chart, without claiming a cause, forecasting, or prescribing an action. Never invent dates, totals, targets, or claims not present in the data. Use kg and percentages. If there is no data, say that clearly.',
        ],
        [
            'role' => 'user',
            'content' => "Dashboard card: {$allowedCharts[$chartId]}\nCurrent data JSON: {$dataJson}",
        ],
    ],
];

$curl = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 20,
]);
$rawResponse = curl_exec($curl);
$httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($rawResponse === false || $httpCode < 200 || $httpCode >= 300) {
    error_log('OpenAI dashboard explanation failed: HTTP ' . $httpCode . ' ' . $curlError);
    explanationResponse(200, [
        'explanation' => generateRuleBasedExplanation($chartId, $request['data'], $language),
        'source' => 'rule_based_fallback',
    ]);
}

$response = json_decode($rawResponse, true);
$explanation = trim((string)($response['choices'][0]['message']['content'] ?? ''));
if ($explanation === '') {
    explanationResponse(200, [
        'explanation' => generateRuleBasedExplanation($chartId, $request['data'], $language),
        'source' => 'rule_based_fallback',
    ]);
}

explanationResponse(200, ['explanation' => $explanation]);
