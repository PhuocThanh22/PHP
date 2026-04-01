# OAuth Setup (Google + Facebook)

## 1) Fill local credentials

Edit `oauth-config.php` and set:

- `GOOGLE_OAUTH_CLIENT_ID`
- `GOOGLE_OAUTH_CLIENT_SECRET`
- `FACEBOOK_OAUTH_CLIENT_ID`
- `FACEBOOK_OAUTH_CLIENT_SECRET`

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

## Notes

- If credentials are missing, API returns: OAuth not configured.
- Social users are upserted into `nguoidung` by email.
