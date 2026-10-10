# CORS

The public API is called from the Aytos24 web frontend, which runs on a different origin. `config/cors.php`
allows **only** the origins listed in `CORS_ALLOWED_ORIGINS`; previously Laravel's default allowed every
origin (`*`).

The web app's host must also be listed in `SANCTUM_STATEFUL_DOMAINS` (host[:port], no scheme) to get
session authentication.

```dotenv
# Comma-separated, exact scheme://host[:port], no trailing slash.
CORS_ALLOWED_ORIGINS=http://localhost:5173
```

| Environment | Value |
| --- | --- |
| Local Docker | `http://localhost:5173` (the default when unset) |
| Frontend e2e suite | `http://localhost:5175` — set for `app-e2e` in `compose.e2e.yaml` |
| Staging / production | the deployed frontend origin(s), e.g. `https://staging.aytos24.example` |

Policy:

- Paths `api/*` and `sanctum/csrf-cookie`; methods `GET`, `HEAD`, `POST`, `PATCH`, `OPTIONS`.
- Request headers `Accept`, `Accept-Language`, `Content-Type`, `X-Requested-With`, `X-XSRF-TOKEN`.
- `supports_credentials` is `true` (since BE-006) so the web app can use Sanctum's session cookie
  (see [`api/authentication.md`](api/authentication.md)). This is only safe because origins are an explicit
  list; never use `*`.
- `Retry-After`, `X-RateLimit-Limit` and `X-RateLimit-Remaining` are exposed so browser clients can read
  them (e.g. how long to wait after a `429`).
- Preflight responses are cached for an hour (`max_age`).
- A future Capacitor build will need its WebView origin added (e.g. `capacitor://localhost`).

After changing `.env`, no restart is needed with php-fpm unless the config is cached
(`php artisan config:clear`).
