<?php
// SMTP mail configuration for JEJ Top Priority Corporation
// Gmail requires a 16-character APP PASSWORD, not your normal Gmail password.
// Keep real credentials in environment variables or local-only smtp_credentials.php.

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
}

if (!defined('SMTP_USER')) {
    define('SMTP_USER', getenv('SMTP_USER') ?: 'jejtoppriority@gmail.com');
}

if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', getenv('SMTP_PASS') ?: 'zgax qjqg nwhc ensq');
}

if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: SMTP_USER);
}

if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'JEJ Top Priority Corporation');
}

if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
}

if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
}

// Localhost diagnostic mode. Set to false before uploading to production.
if (!defined('SMTP_SHOW_ERROR')) {
    define('SMTP_SHOW_ERROR', true);
}
