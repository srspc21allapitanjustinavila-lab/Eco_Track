<?php

require_once __DIR__ . '/../config.php';

function checkDeploymentConfiguration($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }

    echo "PASS: $message\n";
}

$config = file_get_contents(__DIR__ . '/../config.php');
$login = file_get_contents(__DIR__ . '/../login.php');
$passwordReset = file_get_contents(__DIR__ . '/../password_reset_request.php');
$verification = file_get_contents(__DIR__ . '/../password_reset_verification.php');
$userManagement = file_get_contents(__DIR__ . '/../user_management.php');
$uploadRules = file_get_contents(__DIR__ . '/../uploads/.htaccess');
$gitignore = file_get_contents(__DIR__ . '/../.gitignore');
$envExample = file_get_contents(__DIR__ . '/../.env.example');
$schema = file_get_contents(__DIR__ . '/../database_setup.sql');

checkDeploymentConfiguration(
    str_contains($config, "define('DB_HOST', environmentValue('DB_HOST'")
    && str_contains($config, "define('EMAIL_PASSWORD', environmentValue('EMAIL_PASSWORD'))")
    && str_contains($config, "define('OPENAI_API_KEY', environmentValue('OPENAI_API_KEY'))"),
    'Database, mail, and AI settings are read from the environment.'
);
checkDeploymentConfiguration(
    str_contains($envExample, 'PASSWORD_RESET_ENABLED=false')
    && str_contains($config, "define('PASSWORD_RESET_ENABLED', environmentBoolean('PASSWORD_RESET_ENABLED', false))")
    && str_contains($config, 'return PASSWORD_RESET_ENABLED && isSmtpConfigured();'),
    'Password reset is disabled by default until SMTP is configured.'
);
checkDeploymentConfiguration(
    str_contains($login, 'if (isPasswordResetEnabled())')
    && str_contains($passwordReset, '$passwordResetAvailable = isPasswordResetEnabled()')
    && str_contains($verification, "if (!isPasswordResetEnabled())"),
    'Every password-reset entry point honors the feature flag.'
);
checkDeploymentConfiguration(
    str_contains($userManagement, 'storeUserPhotoUpload')
    && str_contains($config, 'MAX_PROFILE_PHOTO_BYTES')
    && str_contains($config, 'random_bytes(16)'),
    'Profile images are validated and given non-predictable filenames.'
);
checkDeploymentConfiguration(
    str_contains($uploadRules, 'Options -Indexes -ExecCGI')
    && str_contains($uploadRules, 'Require all denied'),
    'Upload directories block directory listings and executable PHP-family files.'
);
checkDeploymentConfiguration(
    str_contains($gitignore, '/uploads/*')
    && str_contains($gitignore, '/backups/')
    && str_contains($gitignore, '/.tmp/')
    && str_contains($gitignore, '/admin_profile.jpg')
    && str_contains($gitignore, '.env'),
    'Generated data, caches, backups, and environment files are excluded from Git.'
);
checkDeploymentConfiguration(
    !str_contains($schema, "'admin', 'admin@ecotrack.com'")
    && !str_contains($schema, "'staff', 'staff@ecotrack.com'"),
    'The setup schema does not publish default accounts with weak passwords.'
);
