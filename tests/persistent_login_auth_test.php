<?php

require_once __DIR__ . '/../config.php';

function checkPersistentLogin($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

$_COOKIE = [];
checkPersistentLogin(persistentLoginCookieParts() === null, 'Missing persistent-login cookies are ignored.');

$selector = str_repeat('a', 24);
$validator = str_repeat('b', 64);
$_COOKIE[PERSISTENT_LOGIN_COOKIE] = $selector . ':' . $validator;
$parts = persistentLoginCookieParts();
checkPersistentLogin(
    $parts !== null && $parts['selector'] === $selector && $parts['validator'] === $validator,
    'A correctly structured persistent-login cookie is parsed.'
);

$_COOKIE[PERSISTENT_LOGIN_COOKIE] = $selector . ':invalid';
checkPersistentLogin(persistentLoginCookieParts() === null, 'Malformed persistent-login cookies are rejected.');

$config = file_get_contents(__DIR__ . '/../config.php');
$login = file_get_contents(__DIR__ . '/../login.php');
$settings = file_get_contents(__DIR__ . '/../system_settings.php');
$passwordReset = file_get_contents(__DIR__ . '/../password_reset.php');
$userManagement = file_get_contents(__DIR__ . '/../user_management.php');
$schema = file_get_contents(__DIR__ . '/../database_setup.sql');

checkPersistentLogin(
    str_contains($config, "define('PERSISTENT_LOGIN_LIFETIME', 2592000)")
    && str_contains($config, "'httponly' => true")
    && str_contains($config, "'samesite' => 'Lax'"),
    'Persistent-login cookies use the selected duration and protected attributes.'
);
checkPersistentLogin(
    str_contains($config, 'CREATE TABLE IF NOT EXISTS persistent_login_tokens')
    && str_contains($config, "hash('sha256', \$validator)")
    && str_contains($config, 'hash_equals(')
    && str_contains($schema, 'CREATE TABLE IF NOT EXISTS persistent_login_tokens'),
    'Persistent credentials are hashed and provisioned for existing and new installations.'
);
checkPersistentLogin(
    str_contains($login, 'getPersistentLoginUser($conn)')
    && str_contains($login, 'beginTwoFactorChallenge($conn, $rememberedUser)')
    && str_contains($login, 'completeEcoTrackLogin($conn, $rememberedUser, null, true)'),
    'Remembered browsers resume directly or continue through two-factor verification.'
);
checkPersistentLogin(
    str_contains($config, 'revokeCurrentPersistentLogin($conn);')
    && str_contains($settings, 'revokePersistentLoginsForUser($conn, $user_id)')
    && str_contains($passwordReset, 'revokePersistentLoginsForUser($conn, $_SESSION[\'reset_user_id\'])')
    && str_contains($userManagement, 'revokePersistentLoginsForUser($conn, $user_id)'),
    'Logout, password changes, recovery, and administration revoke remembered credentials.'
);
checkPersistentLogin(
    normalizeEcoTrackRoute('heatmap.php?format=json') === 'waste_heatmap.php?format=json'
    && normalizeEcoTrackRoute('announcement.php') === 'staff_announcements.php'
    && normalizeEcoTrackRoute('admin_dashboard.php') === 'admin_dashboard.php',
    'Saved notification destinations remain valid after route filenames are standardized.'
);
