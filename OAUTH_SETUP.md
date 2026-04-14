# OAuth Setup (Google + Facebook)

## 1) Fill local credentials

Edit `oauth-config.php` and set:

- `GOOGLE_OAUTH_CLIENT_ID`
- `GOOGLE_OAUTH_CLIENT_SECRET`
- `GOOGLE_OAUTH_REDIRECT_URI` (optional but recommended for exact matching)
- `FACEBOOK_OAUTH_CLIENT_ID`
- `FACEBOOK_OAUTH_CLIENT_SECRET`
- `FACEBOOK_OAUTH_REDIRECT_URI` (optional but recommended for exact matching)

For OTP email verify (register + forgot password), also set:

- `SMTP_HOST` (for Gmail: `smtp.gmail.com`)
- `SMTP_PORT` (for Gmail TLS: `587`)
- `SMTP_SECURE` (`tls` or `ssl`)
- `SMTP_USERNAME` (sender mailbox)
- `SMTP_PASSWORD` (mail app password)
- `SMTP_FROM_EMAIL` (usually same as username)
- `SMTP_FROM_NAME` (display sender name)

## 2) Configure redirect URIs in provider consoles

Use these callback URLs for local Laragon (port 8888):

- Google callback:
  - `http://localhost:8888/phuocthanh/PHPCHINH/index.php?api=social_oauth_callback&provider=google`

- Facebook callback:
  - `http://localhost:8888/phuocthanh/PHPCHINH/index.php?api=social_oauth_callback&provider=facebook`

## 3) Required scopes

- Google: `openid email profile`
- Facebook: `email public_profile`

## 4) Test flow

1. Open user home page.
2. Click Login with Google or Facebook.
3. Complete provider consent.
4. App returns to home page and signs in.

## 5) Test OTP flow (register + forgot password)

1. Open user home page.
2. In register popup: enter username + email, click `Gui ma`, then enter OTP and submit.
3. In login popup: click `Quen mat khau?`, enter email, click `Gui ma`, enter OTP + new password and submit.
4. Login again with the new password.

## Notes

- If credentials are missing, API returns: OAuth not configured.
- Social users are upserted into `nguoidung` by email.
- If SMTP is missing, OTP API returns a message asking to configure SMTP in `oauth-config.php`.
