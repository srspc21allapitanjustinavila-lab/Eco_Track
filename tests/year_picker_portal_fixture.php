<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="../">
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
    <style>
        :root {
            --bg-primary: #f5faf8;
            --bg-secondary: #fff;
            --border-color: #b9d7cf;
            --text-primary: #14342b;
            --text-secondary: #49675e;
            --input-bg: #fff;
            --eco-primary: #16735f;
            --eco-primary-soft: #e0f2ec;
            --card-shadow: 0 8px 20px rgba(11, 45, 35, 0.12);
        }

        .calendar-clip {
            position: fixed;
            bottom: 12px;
            left: 12px;
            width: 180px;
            height: 64px;
            overflow: hidden;
            padding: 12px;
            border: 1px solid #b9d7cf;
        }
    </style>
    <script defer src="assets/js/year-navigable-picker.js"></script>
    <script defer src="tests/year_picker_portal_fixture.browser.js"></script>
</head>
<body>
    <div style="width: 180px; margin: 12px;">
        <label for="fixture-standalone-month">Reporting month</label>
        <input id="fixture-standalone-month" type="month" data-year-navigable-picker="month" data-calendar-years='[2026,2025,2024]' data-calendar-months='{"2026":[12],"2025":[2,9],"2024":[]}' value="2026-12">
        <label for="fixture-date">Reporting date</label>
        <input id="fixture-date" type="date" data-year-navigable-picker="date" data-year-picker-date-display="numeric" data-year-picker-allow-empty="true" data-calendar-years='[2026,2025,2024]' data-calendar-months='{"2026":[12],"2025":[2,9],"2024":[1]}' data-calendar-dates='{"2026-12":["2026-12-23"],"2025-02":["2025-02-14"],"2025-09":["2025-09-12"],"2024-01":["2024-01-15"]}' value="2026-12-23">
    </div>
    <form id="period-filter">
        <div class="calendar-clip">
            <label for="fixture-month">Collection Period</label>
            <select id="fixture-year" name="year" aria-label="Collection year">
                <option value="">All Years</option>
                <option value="2026">2026</option>
                <option value="2025">2025</option>
            </select>
            <input id="fixture-month" type="month" name="month" data-year-navigable-picker="month" data-year-picker-external-year="fixture-year" data-year-picker-combined-period="true" data-year-picker-allow-empty="true" data-year-picker-placeholder="All periods" data-calendar-years='[2026,2025]' data-calendar-months='{"2026":[1,6],"2025":[3]}' value="">
        </div>
    </form>
</body>
</html>
