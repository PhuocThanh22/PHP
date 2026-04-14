<?php

return [
    // Google OAuth credentials
    'GOOGLE_OAUTH_CLIENT_ID' => '',
    'GOOGLE_OAUTH_CLIENT_SECRET' => '',
    'GOOGLE_OAUTH_REDIRECT_URI' => 'http://localhost:8888/phuocthanh/PHPCHINH/index.php?api=social_oauth_callback&provider=google',

    // Facebook OAuth credentials
    'FACEBOOK_OAUTH_CLIENT_ID' => '',
    'FACEBOOK_OAUTH_CLIENT_SECRET' => '',
    'FACEBOOK_OAUTH_REDIRECT_URI' => 'http://localhost:8888/phuocthanh/PHPCHINH/index.php?api=social_oauth_callback&provider=facebook',

    // SMTP email (for OTP verify code)
    // Example Gmail SMTP:
    // SMTP_HOST=smtp.gmail.com, SMTP_PORT=587, SMTP_SECURE=tls
    // SMTP_USERNAME=<your_gmail>, SMTP_PASSWORD=<gmail_app_password>
    'SMTP_HOST' => 'smtp.gmail.com',
    'SMTP_PORT' => '587',
    'SMTP_SECURE' => 'tls',
    'SMTP_USERNAME' => '',
    'SMTP_PASSWORD' => '',
    'SMTP_FROM_EMAIL' => '',
    'SMTP_FROM_NAME' => '3 CHU CUN CON',
    'SMTP_TIMEOUT' => '15',
];
