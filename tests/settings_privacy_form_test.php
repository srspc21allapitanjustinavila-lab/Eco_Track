<?php

function checkPrivacySettings($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

$source = file_get_contents(__DIR__ . '/../system_settings.php');
$privacyStart = strpos($source, '<!-- Data Privacy Tab -->');
$privacyEnd = strpos($source, '<?php if ($user_type === \'admin\'): ?><div class="tab-content', $privacyStart);
checkPrivacySettings($privacyStart !== false && $privacyEnd !== false, 'The Data Privacy tab markup is available for verification.');

$privacyMarkup = substr($source, $privacyStart, $privacyEnd - $privacyStart);
$handler = substr($source, 0, $privacyStart);

checkPrivacySettings(substr_count($privacyMarkup, 'SAVE PRIVACY SETTINGS') === 1, 'Data Privacy displays one save button.');
checkPrivacySettings(!str_contains($privacyMarkup, 'SAVE RETENTION POLICY'), 'The separate retention save button is removed.');
checkPrivacySettings(substr_count($privacyMarkup, '<form method="post" action="?tab=privacy"') === 1, 'Data sharing controls use one privacy form.');
checkPrivacySettings(
    str_contains($privacyMarkup, 'data-confirm-title="Save data privacy settings?"')
    && str_contains($privacyMarkup, 'data-confirm-message="Apply the data-sharing and data-export changes to your account?"')
    && str_contains($privacyMarkup, 'data-confirm-action="Save settings"'),
    'Data privacy confirmation explains that the selected settings will be saved.'
);
checkPrivacySettings(
    !str_contains($privacyMarkup, 'DATA RETENTION')
    && !str_contains($privacyMarkup, 'name="data_retention_enabled"')
    && !str_contains($privacyMarkup, 'name="data_retention_months"')
    && strpos($privacyMarkup, 'name="share_anonymized_data"') !== false
    && strpos($privacyMarkup, 'name="save_privacy_settings"') !== false,
    'The Data Privacy form excludes data retention controls.'
);
checkPrivacySettings(!str_contains($handler, 'save_retention_policy'), 'The separate retention request handler is removed.');
checkPrivacySettings(
    str_contains($handler, '$conn->beginTransaction();')
    && str_contains($handler, 'saveUserSettings')
    && !str_contains($handler, 'saveDataRetentionPolicy'),
    'Account privacy settings are saved in one transaction without changing retention policy.'
);
checkPrivacySettings(
    !str_contains($handler, 'data_retention_enabled')
    && !str_contains($handler, 'data_retention_months'),
    'The privacy handler no longer accepts data retention controls.'
);
