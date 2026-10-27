<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/waste_analytics.php';

$checks = 0;
function checkCollectionGroupNormalization($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

[$group, $period] = splitWasteCollectionGroupAndPeriod('Phase 5 Apr 28–May 3, 2025)');
checkCollectionGroupNormalization(
    $group === 'Phase 5' && $period === 'Apr 28–May 3, 2025',
    'A Phase label with a missing opening parenthesis is separated into group and reporting period.'
);
checkCollectionGroupNormalization(
    normalizeWastePhaseLabel(['collection_group' => 'Phase 5 Apr 28–May 3, 2025)']) === 'Phase 5',
    'Analytics places embedded Phase/date labels under their canonical Phase.'
);
checkCollectionGroupNormalization(
    buildActiveWasteCollectionGroups([
        ['collection_group' => 'Phase 5'],
        ['collection_group' => 'Phase 5 Apr 28–May 3, 2025)'],
        ['collection_group' => 'Establishments'],
    ]) === ['Establishments', 'Phase 5'],
    'Collection Group choices do not create a separate malformed Phase/date option.'
);

echo 'PASS: ' . $checks . " collection group normalization checks\n";
