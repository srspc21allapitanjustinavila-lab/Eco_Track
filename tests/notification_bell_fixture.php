<?php
$notificationItems = [];
for ($index = 1; $index <= 10; $index++) {
    $notificationItems[] = [
        'id' => $index,
        'title' => 'Fixture notification ' . $index,
        'text' => 'Browser regression fixture',
        'time' => 'Just now',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="../">
    <?php include __DIR__ . '/../includes/theme_head.php'; ?>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
    <style>
        body {
            padding: 48px;
        }
    </style>
    <script defer src="tests/notification_bell_fixture.browser.js"></script>
</head>
<body>
    <div class="header-icons">
        <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
    </div>
</body>
</html>
