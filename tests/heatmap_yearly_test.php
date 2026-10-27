<?php

require_once __DIR__ . '/../includes/waste_analytics.php';

// Keep production period queries intact while adapting MySQL schema checks.
$database = new class {
    private $sqlite;

    public function __construct()
    {
        $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->sqlite->exec('CREATE TABLE waste_records (id INTEGER PRIMARY KEY, collection_date TEXT, is_active INTEGER, phase_number TEXT, street TEXT, kilogram_of_waste REAL)');
        $this->sqlite->exec('CREATE TABLE waste_daily_allocations (waste_record_id INTEGER, allocation_date TEXT, kilogram_of_waste REAL, unclassified_waste_kg REAL DEFAULT 0)');
        $this->sqlite->exec("INSERT INTO waste_records VALUES
            (1, '2025-12-31', 1, 'Phase 1-A', 'Bell St.', 30),
            (2, '2026-12-31', 1, 'Phase 1A', 'Bell St.', 120),
            (3, '2027-01-01', 1, 'Phase 1A', 'Bell St.', 900),
            (4, '2026-06-01', 0, 'Phase 1A', 'Bell St.', 5000),
            (5, '2024-02-29', 1, 'Phase 5', 'Sodium St.', 366)");
        $this->sqlite->exec("INSERT INTO waste_daily_allocations VALUES
            (1, '2025-12-31', 15, 0), (1, '2026-01-01', 15, 0),
            (2, '2026-12-31', 120, 0), (3, '2027-01-01', 900, 0),
            (4, '2026-06-01', 5000, 0), (5, '2024-02-29', 366, 0)");
    }

    public function query($sql)
    {
        if (preg_match('/^SHOW COLUMNS FROM (waste_records|waste_daily_allocations)$/', $sql, $match)) {
            return $this->sqlite->query("SELECT name AS Field FROM pragma_table_info('" . $match[1] . "')");
        }
        if ($sql === 'SHOW INDEX FROM waste_records') {
            return $this->sqlite->query("SELECT 'idx_waste_records_collection_date' AS Key_name");
        }
        return $this->sqlite->query($sql);
    }

    public function exec($sql)
    {
        if (strpos($sql, 'CREATE TABLE IF NOT EXISTS waste_daily_allocations') === 0) return 0;
        return $this->sqlite->exec($sql);
    }

    public function prepare($sql)
    {
        return $this->sqlite->prepare($sql);
    }
};

$checks = 0;
function checkYearlyHeatmap($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

$year = fetchHeatmapPeriodAnalytics($database, 'year', '2026', '2026-01-01', '2026-01-01');
checkYearlyHeatmap($year['selection']['start'] === '2026-01-01' && $year['selection']['end'] === '2026-12-31', 'Yearly requests cover the whole selected year despite stale daily parameters.');
checkYearlyHeatmap($year['summary']['total_waste'] === 135.0, 'Yearly totals include both year boundaries and exclude other years and inactive records.');
$bell = array_column($year['locations'], null, 'name')['Phase 1A - Bell St.'];
checkYearlyHeatmap($bell['record_count'] === 2 && $bell['total_waste'] === 135.0, 'Same-street annual records combine without duplicating cross-year allocations.');
checkYearlyHeatmap($bell['previous_total_waste'] === 15.0 && $bell['daily_average_kg'] === round(135 / 365, 2), 'Yearly comparisons and daily averages use the correct annual periods.');
$earlier = fetchHeatmapPeriodAnalytics($database, 'year', '2025');
checkYearlyHeatmap($earlier['selection']['period'] === '2025' && $earlier['summary']['total_waste'] === 15.0, 'Changing the selected year changes the total.');
$leap = fetchHeatmapPeriodAnalytics($database, 'year', '2024');
checkYearlyHeatmap($leap['selection']['day_count'] === 366 && $leap['summary']['total_waste'] === 366.0, 'Leap-year totals and day counts remain accurate.');
$empty = fetchHeatmapPeriodAnalytics($database, 'year', '2023');
checkYearlyHeatmap($empty['selection']['period'] === '2023' && $empty['summary']['total_waste'] === 0.0, 'A refreshed year with no remaining records does not silently switch to another year.');
$month = fetchHeatmapPeriodAnalytics($database, 'month', '2026-12', '2026-01-01', '2026-01-01');
checkYearlyHeatmap($month['summary']['total_waste'] === 120.0 && $month['selection']['period'] === '2026-12', 'Monthly requests also ignore stale daily parameters.');
$daily = fetchHeatmapPeriodAnalytics($database, 'week', '', '2026-01-01', '2026-01-01');
checkYearlyHeatmap($daily['summary']['total_waste'] === 15.0 && $daily['selection']['start'] === $daily['selection']['end'], 'Daily range requests keep their exact selected dates.');
echo 'PASS: ' . $checks . " yearly heatmap query checks\n";
