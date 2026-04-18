<?php

return [
    // Google OAuth credentials
    'GOOGLE_OAUTH_CLIENT_ID' => getenv('GOOGLE_OAUTH_CLIENT_ID') ?: '',
    'GOOGLE_OAUTH_CLIENT_SECRET' => getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: '',
    'GOOGLE_OAUTH_REDIRECT_URI' => getenv('GOOGLE_OAUTH_REDIRECT_URI') ?: 'http://localhost:8888/phuocthanh/PHPCHINH/index.php?api=social_oauth_callback&provider=google',

    // Facebook OAuth credentials
    'FACEBOOK_OAUTH_CLIENT_ID' => getenv('FACEBOOK_OAUTH_CLIENT_ID') ?: '',
    'FACEBOOK_OAUTH_CLIENT_SECRET' => getenv('FACEBOOK_OAUTH_CLIENT_SECRET') ?: '',
    'FACEBOOK_OAUTH_REDIRECT_URI' => getenv('FACEBOOK_OAUTH_REDIRECT_URI') ?: 'http://localhost:8888/phuocthanh/PHPCHINH/index.php?api=social_oauth_callback&provider=facebook',

    // SMTP email (for OTP verify code)
    // Example Gmail SMTP:
    // SMTP_HOST=smtp.gmail.com, SMTP_PORT=587, SMTP_SECURE=tls
    // SMTP_USERNAME=<your_gmail>, SMTP_PASSWORD=<gmail_app_password>
    'SMTP_HOST' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'SMTP_PORT' => getenv('SMTP_PORT') ?: '587',
    'SMTP_SECURE' => getenv('SMTP_SECURE') ?: 'tls',
    'SMTP_USERNAME' => getenv('SMTP_USERNAME') ?: '',
    'SMTP_PASSWORD' => getenv('SMTP_PASSWORD') ?: '',
    'SMTP_FROM_EMAIL' => getenv('SMTP_FROM_EMAIL') ?: '',
    'SMTP_FROM_NAME' => getenv('SMTP_FROM_NAME') ?: '3 CHU CUN CON',
    'SMTP_TIMEOUT' => getenv('SMTP_TIMEOUT') ?: '15',
];
