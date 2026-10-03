<?php

require_once __DIR__ . '/../config.php';

function checkNotificationPreference($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

checkNotificationPreference(
    getDisabledNotificationTypes(['user_type' => 'staff'], ['reports_reminder' => 0, 'high_waste_alerts' => 1]) === ['report_reminder'],
    'A staff member who disables report reminders does not receive existing report-reminder items.'
);

checkNotificationPreference(
    getDisabledNotificationTypes(['user_type' => 'admin'], ['reports_reminder' => 1, 'high_waste_alerts' => 0]) === ['high_waste_alert'],
    'An administrator who disables high-waste alerts does not receive existing high-waste items.'
);

checkNotificationPreference(
    getDisabledNotificationTypes(['user_type' => 'staff'], ['reports_reminder' => 1, 'high_waste_alerts' => 0]) === [],
    'Role-specific preferences do not hide unrelated staff notifications.'
);

checkNotificationPreference(
    getDisabledNotificationTypes(['user_type' => 'admin'], ['reports_reminder' => 0, 'high_waste_alerts' => 1]) === [],
    'Role-specific preferences do not hide unrelated administrator notifications.'
);

checkNotificationPreference(
    !areNotificationPreferencesEnabled(['reports_reminder' => 0, 'high_waste_alerts' => 0]),
    'Turning both notification controls off suppresses the entire notification feed.'
);

checkNotificationPreference(
    areNotificationPreferencesEnabled(['reports_reminder' => 1, 'high_waste_alerts' => 0]),
    'Turning either notification control on keeps its enabled notification feed available.'
);
