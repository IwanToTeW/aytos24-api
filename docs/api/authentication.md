# Customer authentication API contract

Ticket: **BE-005**. Agreed on 10 October 2026.

This document defines how Aytos24 customers register, sign in, verify their email, recover their
password, use Google, Facebook and Apple, manage their profile and keep authenticated sessions. The
machine-readable contract is [`openapi.yaml`](openapi.yaml) (tags `Authentication`,
`Social authentication` and `Profile`); this document explains the behaviour behind it. Where the two
disagree, it is a bug: `tests/Feature/Api/V1/Auth/AuthenticationContractTest.php` checks that they agree.

Guests can browse every food discovery endpoint without an account. An account is required before
placing an order.

## Implementation status

Endpoints marked *Implemented* are live. Everything else is *contract only*: no route exists, and
clients must not call it until the ticket in the "Ticket" column ships. In the OpenAPI spec each
operation carries `x-implementation-status` (`implemented` or `contract-only`) and `x-implemented-in`.

| Capability | Status | Ticket |
| --- | --- | --- |
| CSRF initialisation (`GET /sanctum/csrf-cookie`) | **Implemented** | BE-006 |
| Registration and email verification | **Implemented** | BE-006 |
| Login, logout, current user | Contract only | BE-007 |
| Google, Facebook and Apple sign-in | Contract only | BE-008 |
| Password reset and recovery | Contract only | BE-009 |
| Profile retrieval and update | Contract only | BE-010 |
| Linking and unlinking social accounts | Contract only (rules below) | BE-011 |
| Native (Capacitor) token authentication | Strategy only, no endpoints | — |

Platform: Laravel **13.35.0**, PHP 8.4 in Docker, **Laravel Sanctum 4.3** (added in BE-006).
Laravel Socialite is not installed yet; BE-008 adds `laravel/socialite` and, because Socialite has no
built-in Apple driver, `socialiteproviders/apple`.

### What BE-006 implemented

| Piece | Where |
| --- | --- |
| Customer model: the existing `User`, now `MustVerifyEmail` and `HasLocalePreference`; new nullable `phone` and `locale` columns | `app/Models/User.php`, `database/migrations/2026_10_10_000001_*` |
| Sanctum (stateful SPA auth); its `personal_access_tokens` table exists for future native tokens, none are issued | `config/sanctum.php` |
| `POST /api/v1/auth/register` | `RegisterController`, `RegisterCustomerRequest` |
| `GET /api/v1/auth/verify-email/{id}/{hash}` | `VerifyEmailController` |
| `POST /api/v1/auth/email/verification-notification` | `EmailVerificationNotificationController` |
| `GET /sanctum/csrf-cookie`, re-registered with a rate limit | `routes/web.php` |
| Customer representation, reused by BE-007 and BE-010 | `app/Http/Resources/V1/CustomerResource.php` |
| Queued, localised verification email | `app/Notifications/VerifyEmailNotification.php`, `lang/{bg,en}/notifications.php`, `lang/bg.json` |
| Bulgarian validation messages | `lang/bg/validation.php` (rules not translated there fall back to English) |
| Locale from `Accept-Language` on authentication routes | `app/Http/Middleware/SetLocaleFromAcceptLanguage.php` |
| Rate limits and password policy | `config/auth.php`, `app/Providers/AppServiceProvider.php` |
| Queue worker for local Docker | `queue` service in `compose.yaml` |

**Sessions only on authentication routes.** Sanctum's stateful middleware
(`EnsureFrontendRequestsAreStateful`) is applied to the `/api/v1/auth` route group instead of the whole
API (`statefulApi()`), so food discovery stays stateless and never creates session rows. Future
authenticated groups (`/api/v1/me`, checkout) must add the same middleware.

**Registration in detail.**

- `name` and `email` are trimmed (global `TrimStrings` middleware; passwords are never trimmed), the
  email is lowercased before validation, and uniqueness is checked against the lowercased address. A
  concurrent duplicate that slips past validation hits the unique index and is still answered with the
  same `422` on `email`, never a database error.
- Only `name`, `email` and `password` from the request reach the record (`customerAttributes()`); the
  password is hashed by the model's `hashed` cast (bcrypt). Other fields (`id`, `email_verified_at`,
  `phone`, `remember_token`, …) are ignored.
- Laravel's `Registered` event is dispatched; its listener queues the verification email. If
  dispatching fails, the error is reported to the log and registration still answers `201` — the
  customer can resend the email.
- For web app requests the customer is signed in on the `web` guard (with a remember-me cookie when
  `remember` is `true`) and the session ID is regenerated. No token is ever returned.
- **Native limitation:** requests without a web app `Origin`/`Referer` get the account and the email but
  no session and no token, because native token issuance is not implemented yet (section 3).

**Email verification in detail.** The link is checked in this order: signature (else `invalid`),
expiry (else `expired`), customer ID and email hash (else `invalid`), already verified
(`already-verified`); only then is `email_verified_at` set and Laravel's `Verified` event dispatched
(`verified`). The redirect target is always `FRONTEND_URL` + `/email-verified?status=…`; nothing in the
request can change it. Unverified customers can use every authenticated endpoint; routes that need a
verified email use Laravel's `verified` middleware, which answers `403`
`{"message": "Your email address is not verified."}`.

**Resend.** The contract's `202 Accepted` with no body is kept, also for already verified customers
(nothing is sent then). The BE-006 ticket text suggested `200` with
`{"message": "Verification email sent."}`; the approved contract was followed instead so that clients
never depend on message text.

### Mail and queue configuration

Laravel's standard mail settings, all from the environment (never committed):

```dotenv
MAIL_MAILER=smtp                 # local Docker default: log (emails, including links, go to storage/logs)
MAIL_HOST=smtp.provider.example
MAIL_PORT=587
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS=no-reply@aytos24.example
MAIL_FROM_NAME="Aytos24"
APP_NAME=Aytos24                 # shown in the email header and footer
APP_URL=https://api.aytos24.example   # base of the signed verification links
FRONTEND_URL=https://aytos24.example  # target of the verification result redirect
AUTH_VERIFICATION_EXPIRE=1440    # link lifetime in minutes
QUEUE_CONNECTION=database
```

- The verification email is a queued notification (`database` queue, retried 3 times with back-off).
  Registration only inserts the job, so a stopped worker never fails registration; emails go out when a
  worker runs. Locally the `queue` service in `compose.yaml` runs `php artisan queue:listen`; production
  needs a supervised `php artisan queue:work`.
- Failed deliveries are logged by the worker and kept in `failed_jobs` (`php artisan queue:failed`).
  The job payload contains neither the password nor the signed link (the link is built when the email is
  sent).
- `MAIL_MAILER=log` writes whole emails, including working verification links, to the application log.
  Use it only locally.
- The email's language is the customer's stored `locale` (from `Accept-Language` at registration), else
  `APP_LOCALE`. Production sets `APP_LOCALE=bg`.

### Rate limits (configurable)

| Limiter | Default | Environment variable |
| --- | --- | --- |
| `register` (per IP) | 10 per hour | `AUTH_REGISTER_LIMIT_PER_HOUR` |
| `verify-email` (per IP) | 6 per minute | `AUTH_VERIFY_EMAIL_LIMIT_PER_MINUTE` |
| `verification-notification` (per customer) | 6 per minute | `AUTH_VERIFICATION_NOTIFICATION_LIMIT_PER_MINUTE` |
| `csrf-cookie` (per IP) | 60 per minute | `AUTH_CSRF_COOKIE_LIMIT_PER_MINUTE` |

## Contents

1. [Authentication architecture](#1-authentication-architecture)
2. [Browser session authentication](#2-browser-session-authentication)
3. [Future native authentication](#3-future-native-authentication)
4. [Endpoint reference](#4-endpoint-reference)
5. [Request payloads](#5-request-payloads)
6. [Success responses](#6-success-responses)
7. [Validation responses](#7-validation-responses)
8. [HTTP status codes](#8-http-status-codes)
9. [Customer resource schema](#9-customer-resource-schema)
10. [OAuth flow and callback behaviour](#10-oauth-flow-and-callback-behaviour)
11. [Account identity and linking rules](#11-account-identity-and-linking-rules)
12. [Email verification behaviour](#12-email-verification-behaviour)
13. [Password recovery behaviour](#13-password-recovery-behaviour)
14. [Session expiration behaviour](#14-session-expiration-behaviour)
15. [Security requirements](#15-security-requirements)
16. [Frontend integration](#16-frontend-integration)

## 1. Authentication architecture

- **One customer identity.** A customer is a row in `users`. Every sign-in method (email and password,
  Google, Facebook, Apple) and every client (web browser, future native app) resolves to the same row.
  There are no separate web and mobile identities.
- **Two credential types, one guard.** Routes that need a customer use Sanctum's `auth:sanctum`
  middleware. For requests from the web app (an origin in `SANCTUM_STATEFUL_DOMAINS`) it uses the
  session cookie; for other requests it looks for a bearer token. Endpoints do not need to know which
  one was used, except logout.
- **Versioning.** The existing convention is kept: authentication endpoints live under
  `/api/v1/auth`, the customer profile under `/api/v1/me`, separate from food discovery
  (`/api/v1/meals/...`). The only unversioned endpoint is Sanctum's `GET /sanctum/csrf-cookie`.
- **Authentication versus authorisation.** Authentication answers *who is the customer*
  (`401` when unknown). Authorisation answers *what may they do* (`403` when not allowed):
  - Guests may use all public food discovery endpoints.
  - Signed-in customers may read and change **only their own** profile. `/api/v1/me` takes no
    customer ID, so another customer's profile cannot even be addressed.
  - Placing an order (future checkout tickets) requires authentication **and** a verified email.
  - Restaurant staff and administrators are out of scope. They will be added as roles or separate
    guards later; nothing here assumes every `users` row is a customer forever, and no endpoint in
    this contract grants anything beyond the caller's own account.
- **Conventions** are those of the existing API (see the `openapi.yaml` introduction): JSON,
  `snake_case`, nullable fields always present, single resources wrapped in `data`, errors with a
  `message`, Laravel validation errors with `errors`. No custom response envelope is introduced.

## 2. Browser session authentication

The Vue 3 web app uses **Sanctum SPA (stateful) authentication**: an encrypted Laravel session in an
`HttpOnly` cookie, protected by CSRF tokens. The frontend never sees or stores a credential, and must
not keep anything authentication-related in `localStorage` or `sessionStorage`.

### Flow

```text
Vue app (https://aytos24.example)                     API (https://api.aytos24.example)
  |  GET /sanctum/csrf-cookie  (credentials: include)  ->  204, Set-Cookie: XSRF-TOKEN, aytos24_session
  |  POST /api/v1/auth/login   X-XSRF-TOKEN: <cookie>   ->  200 {data: customer}, session ID regenerated
  |  GET  /api/v1/auth/user    (cookie sent)            ->  200 {data: customer}   (page refresh restores state)
  |  POST /api/v1/auth/logout  X-XSRF-TOKEN: <cookie>   ->  204, session invalidated
```

### Cookies

| Cookie | Set by | Flags | Purpose |
| --- | --- | --- | --- |
| `aytos24_session` | any stateful response | `HttpOnly`, `Secure` (production), `SameSite=Lax` | Laravel session ID |
| `XSRF-TOKEN` | any stateful response | readable by JavaScript, `Secure` (production), `SameSite=Lax` | CSRF token for the `X-XSRF-TOKEN` header |
| `remember_web_…` | login/register/social with `remember` | `HttpOnly`, `Secure` (production), `SameSite=Lax` | Restores the session after it expires |
| `aytos24_oauth_state` | social redirect endpoint | `HttpOnly`, `Secure`, `SameSite=None`, path `/api/v1/auth/social`, 10 minutes, encrypted | OAuth `state`, intended path, remember flag |

`SameSite=Lax` works because the web app and the API are **same-site**: in production they must share
a registrable domain (e.g. `aytos24.example` and `api.aytos24.example`); locally `localhost:5173` and
`localhost:8000` are the same site. A deployment on unrelated domains would need `SameSite=None` and is
not supported by this contract.

### Required configuration

Applied in BE-006 (`.env.example`, `config/cors.php`, `config/sanctum.php`). Production values:

```dotenv
FRONTEND_URL=https://aytos24.example
SANCTUM_STATEFUL_DOMAINS=aytos24.example
SESSION_COOKIE=aytos24_session
SESSION_DOMAIN=.aytos24.example
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_HTTP_ONLY=true
SESSION_LIFETIME=120
```

Locally: `FRONTEND_URL=http://localhost:5173`, `SANCTUM_STATEFUL_DOMAINS=localhost:5173`,
`SESSION_DOMAIN=null`, `SESSION_SECURE_COOKIE=false`. `SANCTUM_STATEFUL_DOMAINS` lists hosts with port
and without scheme; `CORS_ALLOWED_ORIGINS` lists full origins.

- `routes/api.php`: Sanctum's `EnsureFrontendRequestsAreStateful` on the authentication route groups
  (not `statefulApi()` on the whole API), so their requests from stateful origins get sessions and CSRF
  verification while food discovery stays stateless.
- `config/cors.php`: paths `api/*` and `sanctum/csrf-cookie`; methods `GET`, `HEAD`, `POST`, `PATCH`,
  `OPTIONS`; headers include `X-XSRF-TOKEN` and `X-Requested-With`; `supports_credentials` is `true`.
  `allowed_origins` must stay an explicit list — a credentialed wildcard is never allowed.
- Behind a TLS-terminating proxy, configure trusted proxies so `APP_URL`, signed links and secure
  cookies see the original `https` scheme and host.
- HTTPS is mandatory in production for both the web app and the API.

## 3. Future native authentication

Native Android and iOS apps (Capacitor) are **not** implemented by this contract. When they are, they
will use Sanctum **personal access tokens**:

| | Browser (now) | Native app (future) |
| --- | --- | --- |
| Credential | `HttpOnly` session cookie | Sanctum personal access token |
| Sent as | cookie, automatically | `Authorization: Bearer <token>` |
| Stored in | browser cookie jar only | iOS Keychain / Android Keystore (secure storage plugin), never `localStorage` or `Preferences` |
| CSRF | `X-XSRF-TOKEN` required | not applicable, header omitted |
| Logout | session invalidated | current token revoked |
| Expiry | idle timeout, remember-me cookie | token expiry set by the native ticket |
| Customer | same `users` row | same `users` row |

Rules the native ticket must follow:

- Tokens are issued by a dedicated endpoint (for example `POST /api/v1/auth/tokens` with a
  `device_name`), defined in that ticket. It is not part of this contract version.
- Tokens are never placed in URLs, deep links or redirect query strings.
- OAuth client secrets never ship in the app. Native social sign-in opens the system browser
  (`ASWebAuthenticationSession` / Custom Tabs) on the backend redirect endpoint; the backend callback
  then hands the app a **one-time, short-lived code** through its deep link, which the app exchanges
  for a token over HTTPS (PKCE-protected). Native Sign in with Apple may instead send Apple's identity
  token to a backend verification endpoint.
- `POST /api/v1/auth/logout` with a bearer token revokes only that token. Other devices stay signed in.
- The Capacitor WebView origin (e.g. `capacitor://localhost`) is added to CORS only when needed.

## 4. Endpoint reference

All paths are relative to the API host. Base URL locally: `http://localhost:8000`.

| Method | Path | Authentication | Status | Ticket | Purpose |
| --- | --- | --- | --- | --- | --- |
| `GET` | `/sanctum/csrf-cookie` | Guest | Implemented | BE-006 | Initialise CSRF protection (browser only) |
| `POST` | `/api/v1/auth/register` | Guest | Implemented | BE-006 | Register a customer |
| `POST` | `/api/v1/auth/login` | Guest | Contract only | BE-007 | Sign in with email and password |
| `POST` | `/api/v1/auth/logout` | Required | Contract only | BE-007 | End the current session or revoke the current token |
| `GET` | `/api/v1/auth/user` | Required | Contract only | BE-007 | Get the authenticated customer |
| `POST` | `/api/v1/auth/forgot-password` | Guest | Contract only | BE-009 | Request a password reset email |
| `POST` | `/api/v1/auth/reset-password` | Guest | Contract only | BE-009 | Reset the password with a token |
| `GET` | `/api/v1/auth/verify-email/{id}/{hash}` | Signed link | Implemented | BE-006 | Verify an email address (redirects) |
| `POST` | `/api/v1/auth/email/verification-notification` | Required | Implemented | BE-006 | Resend the verification email |
| `GET` | `/api/v1/auth/social/{provider}/redirect` | Guest | Contract only | BE-008 | Start Google, Facebook or Apple sign-in |
| `GET` | `/api/v1/auth/social/{provider}/callback` | Guest | Contract only | BE-008 | OAuth callback for Google and Facebook |
| `POST` | `/api/v1/auth/social/{provider}/callback` | Guest | Contract only | BE-008 | OAuth callback for Apple (`form_post`) |
| `GET` | `/api/v1/me` | Required | Contract only | BE-010 | Get the customer's profile |
| `PATCH` | `/api/v1/me` | Required | Contract only | BE-010 | Update the customer's profile |

*Guest* means no authentication is required. *Required* means a valid session cookie (browser) or
bearer token (native); otherwise `401`. *Signed link* means the URL's signature is the credential.

Calling `register` or `login` while already signed in signs the current customer out first and starts
a new session; there is no "already authenticated" error.

### Rate limits

All `/api/*` routes keep the existing limit of 60 requests per minute per IP. Authentication endpoints
add these named limiters (BE-007 should also key the general `api` limiter by customer ID for
signed-in requests, so customers behind a shared mobile-carrier IP are not throttled together):

| Endpoint | Limit |
| --- | --- |
| `GET /sanctum/csrf-cookie` | 60 per minute per IP — Sanctum's own route is unthrottled and creates a database session row per call, so BE-007 re-registers it (`Sanctum::ignoreRoutes()`) with this limit |
| `register` | 10 per hour per IP |
| `login` | 5 failed attempts per minute per email + IP; 20 requests per minute per IP |
| `forgot-password` | 5 per hour per email; 20 per hour per IP |
| `reset-password` | 5 per minute per IP |
| `verify-email` | 6 per minute per IP |
| `email/verification-notification` | 6 per minute per customer |
| `social/{provider}/redirect`, `…/callback` | 20 per minute per IP |

Every limit answers `429` with a `Retry-After` header and the same body as the rest of the API.

### Request and response examples

All JSON requests send `Accept: application/json` and `Content-Type: application/json`. Browser
requests additionally send cookies (`credentials: 'include'`) and, on `POST`/`PATCH`, `X-XSRF-TOKEN`.
Native requests send `Authorization: Bearer <token>` instead.

#### `GET /sanctum/csrf-cookie`

```http
GET /sanctum/csrf-cookie HTTP/1.1
Origin: https://aytos24.example

HTTP/1.1 204 No Content
Set-Cookie: XSRF-TOKEN=eyJpdiI6...; Path=/; Domain=.aytos24.example; Secure; SameSite=Lax
Set-Cookie: aytos24_session=eyJpdiI6...; Path=/; Domain=.aytos24.example; Secure; HttpOnly; SameSite=Lax
```

#### `POST /api/v1/auth/register`

Request:

```json
{
  "name": "Ivan",
  "email": "ivan@example.com",
  "password": "ExamplePassword123!",
  "password_confirmation": "ExamplePassword123!"
}
```

`201 Created` (session cookie set, verification email queued):

```json
{
  "data": {
    "id": 123,
    "name": "Ivan",
    "email": "ivan@example.com",
    "email_verified": false,
    "phone": null,
    "created_at": "2026-10-10T09:00:00Z"
  }
}
```

`422` when the email is taken:

```json
{
  "message": "The email has already been taken.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```

#### `POST /api/v1/auth/login`

Request:

```json
{
  "email": "ivan@example.com",
  "password": "ExamplePassword123!",
  "remember": true
}
```

`200 OK` (session ID regenerated):

```json
{
  "data": {
    "id": 123,
    "name": "Ivan",
    "email": "ivan@example.com",
    "email_verified": true,
    "phone": null,
    "created_at": "2026-10-10T09:00:00Z"
  }
}
```

`422` for a wrong password, an unknown email, or a social-only account without a password — always
this exact shape, so account existence is not revealed:

```json
{
  "message": "These credentials do not match our records.",
  "errors": {
    "email": ["These credentials do not match our records."]
  }
}
```

#### `POST /api/v1/auth/logout`

No request body. `204 No Content`; the session is invalidated, a fresh `XSRF-TOKEN` cookie is set and
the remember-me cookie is expired. Without a session: `401`.

#### `GET /api/v1/auth/user`

`200 OK`:

```json
{
  "data": {
    "id": 123,
    "name": "Ivan",
    "email": "ivan@example.com",
    "email_verified": true,
    "phone": null,
    "created_at": "2026-10-10T09:00:00Z"
  }
}
```

`401 Unauthorized`:

```json
{
  "message": "Unauthenticated."
}
```

#### `POST /api/v1/auth/forgot-password`

Request:

```json
{
  "email": "ivan@example.com"
}
```

`202 Accepted`, no body — for registered and unknown emails alike. `422` only for a missing or
malformed email.

#### `POST /api/v1/auth/reset-password`

Request:

```json
{
  "email": "ivan@example.com",
  "token": "reset-token",
  "password": "NewExamplePassword123!",
  "password_confirmation": "NewExamplePassword123!"
}
```

`204 No Content` on success. `422` for an invalid, expired or used token:

```json
{
  "message": "This password reset token is invalid.",
  "errors": {
    "token": ["This password reset token is invalid."]
  }
}
```

#### `GET /api/v1/auth/verify-email/{id}/{hash}`

Opened from the email, not called by the app:

```http
GET /api/v1/auth/verify-email/123/9f0d4c3b5c0f0a4f5d3e1e8a7b2c6d9e0f1a2b3c?expires=1791709200&signature=4f1c… HTTP/1.1

HTTP/1.1 302 Found
Location: https://aytos24.example/email-verified?status=verified
```

#### `POST /api/v1/auth/email/verification-notification`

No request body. `202 Accepted`, no body (also when already verified). Without a session: `401`.

#### `GET /api/v1/auth/social/{provider}/redirect`

A full-page navigation:

```http
GET /api/v1/auth/social/google/redirect?intended=%2Fcheckout&remember=1 HTTP/1.1

HTTP/1.1 302 Found
Location: https://accounts.google.com/o/oauth2/v2/auth?client_id=…&redirect_uri=https%3A%2F%2Fapi.aytos24.example%2Fapi%2Fv1%2Fauth%2Fsocial%2Fgoogle%2Fcallback&scope=openid+email+profile&response_type=code&state=3f6c1b2e9d8a4c7f
Set-Cookie: aytos24_oauth_state=eyJpdiI6...; Path=/api/v1/auth/social; Max-Age=600; Secure; HttpOnly; SameSite=None
```

Unknown provider: `404` `{"message": "Not Found"}`.

#### `GET /api/v1/auth/social/{provider}/callback` (Google, Facebook)

```http
GET /api/v1/auth/social/google/callback?code=4%2F0Ab…&state=3f6c1b2e9d8a4c7f HTTP/1.1
Cookie: aytos24_oauth_state=eyJpdiI6...

HTTP/1.1 303 See Other
Location: https://aytos24.example/auth/social/callback?status=success&provider=google&intended=%2Fcheckout&new_account=0
Set-Cookie: aytos24_session=eyJpdiI6...; Secure; HttpOnly; SameSite=Lax
Set-Cookie: aytos24_oauth_state=deleted; Max-Age=0; Path=/api/v1/auth/social
```

#### `POST /api/v1/auth/social/{provider}/callback` (Apple)

```http
POST /api/v1/auth/social/apple/callback HTTP/1.1
Origin: https://appleid.apple.com
Content-Type: application/x-www-form-urlencoded
Cookie: aytos24_oauth_state=eyJpdiI6...

state=3f6c1b2e9d8a4c7f&code=c1a2b3…&id_token=eyJraWQi…&user=%7B%22name%22%3A%7B%22firstName%22%3A%22Ivan%22%7D%7D

HTTP/1.1 303 See Other
Location: https://aytos24.example/auth/social/callback?status=success&provider=apple&intended=%2F&new_account=1
```

#### `GET /api/v1/me`

`200 OK` — identical to `GET /api/v1/auth/user`:

```json
{
  "data": {
    "id": 123,
    "name": "Ivan",
    "email": "ivan@example.com",
    "email_verified": true,
    "phone": null,
    "created_at": "2026-10-10T09:00:00Z"
  }
}
```

#### `PATCH /api/v1/me`

Request:

```json
{
  "name": "Ivan Totev",
  "phone": "0888 123 456"
}
```

`200 OK`:

```json
{
  "data": {
    "id": 123,
    "name": "Ivan Totev",
    "email": "ivan@example.com",
    "email_verified": true,
    "phone": "+359888123456",
    "created_at": "2026-10-10T09:00:00Z"
  }
}
```

`422` for an invalid phone number:

```json
{
  "message": "The phone field must be a valid phone number.",
  "errors": {
    "phone": ["The phone field must be a valid phone number."]
  }
}
```

## 5. Request payloads

All string inputs are trimmed; empty strings become `null` (Laravel's default middleware). Emails are
lowercased before validation and storage.

| Endpoint | Field | Rules |
| --- | --- | --- |
| register | `name` | required, string, 1–255 |
| register | `email` | required, valid RFC email, max 255, unique among customers (case-insensitive) |
| register | `password` | required, password policy, `confirmed` |
| register | `password_confirmation` | required, equals `password` |
| register, login | `remember` | optional boolean, default `false` |
| login | `email` | required, valid email, max 255 |
| login | `password` | required string (policy not applied at login) |
| forgot-password | `email` | required, valid email, max 255 |
| reset-password | `email`, `token` | required |
| reset-password | `password`, `password_confirmation` | as register |
| `PATCH /me` | `name` | optional; if present: string, 1–255, not `null` |
| `PATCH /me` | `phone` | optional; `null`/`""` removes it; otherwise normalised to E.164 |
| `PATCH /me` | `email`, `password` | prohibited (`422`) |

**Password policy** (`Password::defaults()` in BE-006): 8–128 characters, at least one letter and one
digit, and in production not present in known breaches (`uncompromised()`, Have I Been Pwned
k-anonymity range API). The same policy applies to registration and password reset.

**Not collected at registration:** phone, delivery address, date of birth. They are collected
progressively when a feature needs them (phone via `PATCH /me` before the first order, addresses in a
later checkout ticket).

**PATCH semantics:** a field that is absent is unchanged; `null` clears a nullable field (`phone`) and
is rejected for a required one (`name`); a value equal to the current one is accepted and changes
nothing. `{}` is valid. Unknown fields such as `id`, `email_verified` or `created_at` are ignored —
mass assignment uses only validated `name` and `phone`. Changing the email needs its own
re-verification flow (a later ticket) and changing the password needs the current password; neither
goes through this endpoint.

**Phone normalisation:** remove spaces, `-`, `.`, `(`, `)`; replace a leading `00` with `+`; replace a
single leading `0` with `+359`; the result must match `^\+[1-9][0-9]{7,14}$` (E.164). Examples:
`0888 123 456`, `+359 888 123 456` and `00359888123456` are all stored as `+359888123456`.

## 6. Success responses

| Endpoint | Status | Body |
| --- | --- | --- |
| `GET /sanctum/csrf-cookie` | `204` | none |
| `POST /auth/register` | `201` | customer resource |
| `POST /auth/login` | `200` | customer resource |
| `POST /auth/logout` | `204` | none |
| `GET /auth/user` | `200` | customer resource |
| `POST /auth/forgot-password` | `202` | none |
| `POST /auth/reset-password` | `204` | none |
| `GET /auth/verify-email/{id}/{hash}` | `302` | redirect to the frontend |
| `POST /auth/email/verification-notification` | `202` | none |
| `GET /auth/social/{provider}/redirect` | `302` | redirect to the provider |
| `GET`/`POST /auth/social/{provider}/callback` | `303` | redirect to the frontend |
| `GET /me` | `200` | customer resource |
| `PATCH /me` | `200` | customer resource |

Every response that returns a customer uses the **same** resource (section 9) wrapped in `data`.

## 7. Validation responses

Validation errors use Laravel's standard format with status `422`:

```json
{
  "message": "The name field is required. (and 2 more errors)",
  "errors": {
    "name": ["The name field is required."],
    "email": ["The email field must be a valid email address."],
    "password": ["The password field confirmation does not match."]
  }
}
```

- `errors` has one key per invalid field; each value is a non-empty list of messages.
- `message` is Laravel's summary (first error plus a count). The ticket's example text
  `"The given data was invalid."` is the pre-Laravel-9 wording; the existing API already returns the
  current wording, so it is kept.
- Messages are translated (section 15, *Internationalisation*). Clients branch on **status and field
  names**, never on message text, and may display the messages as they are.
- Invalid credentials are reported on `email`; invalid reset tokens on `token`.

## 8. HTTP status codes

| Situation | Status | Body |
| --- | --- | --- |
| Successful registration | `201` | customer |
| Successful login | `200` | customer |
| Successful logout | `204` | none |
| Invalid credentials | `422` | validation error on `email` |
| Validation failure | `422` | validation error |
| Unauthenticated request (never signed in, signed out, token revoked) | `401` | `{"message": "Unauthenticated."}` |
| Expired session | `401` (or `419` on `POST`/`PATCH`, see section 14) | as above |
| Missing or stale CSRF token (browser) | `419` | `{"message": "CSRF token mismatch."}` |
| Unauthorised action (e.g. ordering with an unverified email, future) | `403` | `{"message": "…"}` |
| Invalid password reset token | `422` | validation error on `token` |
| Expired password reset token | `422` | same as invalid |
| OAuth failure (cancelled, bad state, provider error, …) | `303` | redirect with `status=error&error=<code>` |
| Unsupported social provider | `404` | `{"message": "Not Found"}` |
| Rate limit exceeded | `429` | `{"message": "Too Many Attempts."}` + `Retry-After` |
| Unexpected server error | `500` | `{"message": "Server Error"}` |
| Maintenance | `503` | `{"message": "Service Unavailable"}` |

The `429` body is Laravel's default and is what `GET /api/v1/meals/today` already returns, so it is
kept instead of the ticket's example wording; clients rely on the status and `Retry-After`.

## 9. Customer resource schema

`components/schemas/Customer` in `openapi.yaml`, always wrapped as `{"data": {...}}`:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | customer ID |
| `name` | string or `null` | `null` only after a social sign-in that shared no name (Apple); ask for it with `PATCH /me` |
| `email` | string | lowercased; may be an Apple private relay address |
| `email_verified` | boolean | derived from `email_verified_at`; the timestamp itself is not exposed |
| `phone` | string or `null` | E.164, e.g. `+359888123456` |
| `created_at` | string | RFC 3339 in UTC, e.g. `2026-10-10T09:00:00Z` |

Never included: password hash, `remember_token`, provider tokens or provider user IDs, the list of
linked providers, session or token metadata, IP addresses, `updated_at`. The schema has
`additionalProperties: false`; adding a field means changing this contract first. Customer
timestamps are UTC, unlike meal timestamps, which use the restaurant's offset.

## 10. OAuth flow and callback behaviour

Social sign-in is **backend-controlled** with Laravel Socialite (BE-008). The frontend only navigates
to the redirect endpoint and later receives the outcome; it never handles authorization codes,
provider tokens or client secrets.

```text
Browser                          Aytos24 API                                    Provider
  | GET /auth/social/google/redirect?intended=/checkout
  |----------------------------->| create state, Set-Cookie aytos24_oauth_state
  |<---- 302 to provider --------|
  |------------------------------------------------------------------------------> consent
  |<----------------------------------------------- 302 / form_post to callback ---|
  | GET|POST /auth/social/google/callback?code=…&state=…
  |----------------------------->| check state = cookie, exchange code (server to server)
  |                              | apply identity rules (section 11), log in, regenerate session
  |<---- 303 {FRONTEND_URL}/auth/social/callback?status=…&provider=…&intended=…
```

### Providers

| Provider | `provider` | Scopes | Callback method | Identifier | Email trust |
| --- | --- | --- | --- | --- | --- |
| Google | `google` | `openid email profile` | `GET` | `sub` | trusted when `email_verified` is `true` |
| Facebook | `facebook` | `email public_profile` | `GET` | app-scoped user ID | **never** trusted (no verification claim); email may be absent |
| Apple | `apple` | `name email` | `POST` (`response_mode=form_post`) | `sub` | trusted when `email_verified` is `true`; may be a private relay |

Any other `provider` value, or a callback with the wrong method for the provider, returns `404`.

### State and intended destination

- `state` is a random value generated per attempt and stored, with the validated `intended` path and
  `remember` flag, in the encrypted `aytos24_oauth_state` cookie (10 minutes). The callback rejects a
  missing, expired or mismatching state with `error=invalid_state`, and the cookie is deleted after any
  callback.
- The cookie, not the session, is used because Apple's callback is a cross-site `POST`: the
  `SameSite=Lax` session cookie is not sent with it. For the same reason the Apple callback is
  excluded from CSRF verification, and the social routes run with session middleware regardless of
  `Origin`, so the callback can start the customer's session.
- `intended` must be a path on the frontend: it starts with exactly one `/`, contains no `\`, no
  control characters and no scheme, is at most 2,048 characters, and is not an auth page (`/login`,
  `/register`, `/auth/…`). Anything else becomes `/`. The backend always builds the final URL as
  `FRONTEND_URL` + path, so a redirect can never leave the frontend origin.

### Callback outcome

The callback **always** answers `303 See Other` to `{FRONTEND_URL}/auth/social/callback` with these
query parameters (`x-frontend-redirect` in `openapi.yaml`). No token of any kind is ever in the URL.

| Parameter | Values |
| --- | --- |
| `status` | `success` or `error` |
| `provider` | `google`, `facebook`, `apple` |
| `intended` | the validated path (`/` by default) |
| `new_account` | with `success`: `1` if this sign-in created the account, else `0` |
| `error` | with `error`: one of the codes below |

| `error` | When | Suggested frontend message |
| --- | --- | --- |
| `cancelled` | The customer declined (`access_denied`, `user_cancelled_authorize`) | No message; back to sign-in |
| `invalid_state` | State missing, expired (over 10 minutes) or not matching | "Sign-in timed out, please try again" |
| `provider_error` | Provider error, code exchange failure, invalid ID token, provider unreachable | "Could not sign in with {provider}" |
| `email_missing` | The provider returned no email (possible with Facebook) | "Please use another sign-in method" |
| `account_exists` | The email belongs to an existing customer and linking is not allowed (section 11) | "Sign in with your existing method, then link {provider} in your account" |

On success the session cookie is set (remember-me if requested); the frontend page then calls
`GET /api/v1/auth/user` and navigates to `intended`. `new_account=1` is the cue to ask for missing
details such as `name` or `phone`.

### Provider secrets

`GOOGLE_CLIENT_ID/SECRET`, `FACEBOOK_CLIENT_ID/SECRET`, `APPLE_CLIENT_ID` (Services ID),
`APPLE_TEAM_ID`, `APPLE_KEY_ID` and `APPLE_PRIVATE_KEY` live only in backend environment variables
(`config/services.php`). Apple's client secret is a JWT signed with the private key and must be
regenerated before it expires (at most every 6 months). Provider access and refresh tokens are used
once during the callback and **never stored or returned**: Aytos24 needs the identity, not API access.

## 11. Account identity and linking rules

### Data model (BE-008, BE-011)

- `users`: the customer. `password` becomes nullable (social-only customers have none).
- `social_accounts`: `user_id`, `provider`, `provider_user_id`, `provider_email` (informational),
  timestamps. Unique on (`provider`, `provider_user_id`): a provider identity belongs to at most one
  customer. Unique on (`user_id`, `provider`): at most one account per provider per customer.
- The provider identifier (`sub` or app-scoped ID) is the identity. A provider email is only a
  contact address and is **never** used to find an existing link.

### Callback decision order

1. **Known identity.** (`provider`, `provider_user_id`) exists → sign in that customer. Name and email
   are not overwritten from the provider.
2. **No email.** The provider returned no email → `error=email_missing`. Every customer has an email
   (receipts, password recovery), so no account is created.
3. **Email belongs to an existing customer.** Link and sign in **only if all** of these hold:
   the provider is Google or Apple, the provider asserts `email_verified: true`, **and** the existing
   customer's email is already verified. Otherwise → `error=account_exists` and nothing is linked.
   This prevents pre-account takeover (someone registering a victim's address with a password before
   the victim signs in with Google) and never trusts Facebook emails. Matching is on the lowercased
   email.
4. **New customer.** Create the customer with the provider's name (or `null`), the lowercased email
   and `password` `null`, then create the link. `email_verified_at` is set when the provider asserts a
   verified email (Google, Apple); otherwise (Facebook) the verification email is sent as for
   registration. `new_account=1`.

### Linking and unlinking (BE-011)

- A signed-in customer links another provider from their account settings, which starts the same
  redirect flow with a link intent (parameter defined in BE-011). The identity is attached to the
  **signed-in** customer regardless of email; if it is already linked to a different customer, the
  link is refused.
- Linking requires a recent authentication (password confirmation or sign-in within the last
  10 minutes).
- A customer cannot remove their last sign-in method: unlinking is refused if no other provider is
  linked and no password is set. Social-only customers set a password through forgot-password.

### Apple specifics

- With *Hide My Email*, the email is a private relay address (`…@privaterelay.appleid.com`). It is
  stored and treated like any other address: it is real, verified and unique to Aytos24. It will not
  match an existing account under the customer's real address, so such customers link Apple from
  their account instead of expecting automatic matching. Sending to relay addresses requires
  registering Aytos24's sending domain with Apple.
- Apple sends the customer's **name only on the first authorization** (the `user` form field). The
  backend stores it on that first callback; if it is missing or the first callback failed, `name`
  stays `null` and the frontend asks for it. The email and `email_verified` come from the ID token on
  every sign-in.
- Apple's server-to-server notifications (consent revoked, account deleted, relay email changed) are
  out of scope for BE-008 and noted for a later ticket.

## 12. Email verification behaviour

- Registration (and a Facebook sign-up) queues a verification email containing a signed URL:
  `{APP_URL}/api/v1/auth/verify-email/{id}/{hash}?expires=…&signature=…`. `hash` is the SHA-1 of the
  email address, so the link stops working if the email changes.
- Links are valid for **24 hours** (`auth.verification.expire = 1440`).
- Opening the link does **not** require being signed in (it may be opened on another device) and
  **never** signs anyone in. It always redirects (`302`) to
  `{FRONTEND_URL}/email-verified?status=verified|already-verified|expired|invalid`; it never returns
  JSON for the outcome.
- Unverified customers can sign in, use `/auth/user`, `/me` and resend the email. Actions that need
  a verified email (placing orders) answer `403` with
  `{"message": "Your email address is not verified."}` until then.
- Customers created through Google or Apple with a verified email are verified immediately.
- `POST /api/v1/auth/email/verification-notification` re-sends the email (`202`); if already verified,
  nothing is sent and the response is the same.

## 13. Password recovery behaviour

- `POST /forgot-password` always answers `202` with no body, whether or not the account exists, and
  whether or not the broker's 60-second per-email throttle suppressed the email. The route limiter
  (`429`) applies to every email equally, so it reveals nothing either.
- For an existing customer, a reset email is queued with a link to the **frontend**:
  `{FRONTEND_URL}/reset-password#token=<token>&email=<email>`. The token is in the URL fragment so it is
  never sent to any server, proxy log or `Referer`. The frontend reads it, removes it from the address
  bar (`history.replaceState`) and posts it to `/reset-password`.
- Tokens are stored hashed (`password_reset_tokens`), expire after **60 minutes** and are deleted after
  use. Requesting a new link replaces the previous token. Tokens are never returned by the API, in any
  environment.
- `POST /reset-password` answers `422` on `token` for invalid, expired, used or foreign tokens and for
  unknown emails, with identical bodies.
- A successful reset (`204`): sets the new hashed password, rotates `remember_token`, deletes all of the
  customer's sessions and Sanctum tokens, and sends a "your password was changed" notification. The
  customer is not signed in and signs in with the new password.
- Social-only customers (no password) use the same flow to set a password.

## 14. Session expiration behaviour

- Browser sessions expire after `SESSION_LIFETIME` (120 minutes) **without activity**; each request
  extends them. With `remember`, the remember-me cookie silently starts a new session on the next
  request after expiry, so the customer stays signed in across days and browser restarts until they
  sign out.
- Closing or refreshing the tab does not end the session (`SESSION_EXPIRE_ON_CLOSE=false`).
- When a session has expired (and no remember-me cookie applies):
  - `GET` requests to authenticated endpoints answer `401 {"message": "Unauthenticated."}`.
  - `POST`/`PATCH` requests from the browser may answer `419` first, because the CSRF token belonged
    to the expired session. After refreshing the CSRF cookie and retrying, they answer `401`.
- Public endpoints (meals) are unaffected by an expired session.
- Sessions also end on logout, on a password reset (all sessions) and when the stored password hash
  changes (Sanctum's `AuthenticateSession`).
- Native tokens (future) expire as defined by the native ticket and answer `401` when expired or revoked.

## 15. Security requirements

| Requirement | How |
| --- | --- |
| Password hashing | Laravel `Hash` (bcrypt, the project default) via the `hashed` cast; passwords never stored or logged in plain text |
| Secure session cookies | `HttpOnly`, `Secure` in production, `SameSite=Lax`, encrypted by Laravel |
| CSRF protection | Sanctum `statefulApi()`; `X-XSRF-TOKEN` on every browser `POST`/`PATCH`; only the Apple callback is exempt (protected by `state`) |
| Session fixation | Session ID regenerated after register, login and social sign-in |
| Logout | Session invalidated and CSRF token regenerated; token clients revoke the current token |
| Rate limiting | Named limiters per section 4; `429` + `Retry-After` |
| No account enumeration | Login and forgot/reset-password responses identical for known and unknown accounts. Registration reports duplicates (`422`), an accepted trade-off mitigated by its rate limit |
| OAuth state | Random per attempt, encrypted cookie, single-use, 10 minutes |
| Safe redirects | Only `FRONTEND_URL` + validated path; provider redirect URIs fixed in configuration |
| Input validation | Form Requests with the rules in section 5; only validated fields are persisted |
| HTTPS | Required in production for the API, the web app and all OAuth redirect URIs |
| Logging | Never log request bodies or headers of auth endpoints. `password`, `password_confirmation`, `current_password` and `token` are excluded from flashed input and exception context; OAuth `code`, `id_token` and provider tokens are never logged |
| No tokens in URLs | No access token, session ID or provider token in any URL or redirect. The only secrets in URLs are the single-purpose, expiring verification signature and the reset token in a URL fragment |
| No secrets in clients | OAuth client secrets and Apple's private key exist only on the backend |
| Minimal data | The customer resource exposes only the fields in section 9 |
| Server errors | `500` with a generic message; never stack traces or internal details (`APP_DEBUG=false` in production) |

### Distinguishing failures in the client

- `401` → the customer is signed out (clear the auth store, offer sign-in).
- `419` → refresh CSRF (`GET /sanctum/csrf-cookie`) and retry once.
- `403` → signed in but not allowed (e.g. verify your email).
- `422` → show field errors.
- `429` → wait `Retry-After` seconds.
- `5xx` → server problem, keep the auth state and show a retry message.
- No HTTP response at all (Axios `error.response` undefined) → network failure; keep the auth state.

Every auth error response is JSON, so a `401` is always distinguishable from a network or server
failure.

### Internationalisation

- Bulgarian (`bg`) is the default language; English (`en`) is supported.
- The API selects the locale per request from `Accept-Language` (`bg` or `en`); without a supported
  value it uses `APP_LOCALE` (`bg` in production). This affects only message text, never the
  structure, field names, status codes or error codes.
- The customer's locale at registration (updated at each sign-in) is stored on the account and used for
  emails sent outside a request (queued verification and reset emails), via Laravel's
  `HasLocalePreference`.
- BE-006 adds `lang/bg` translations (validation, `auth`, `passwords` and email texts) without new
  dependencies.
- Frontends may display API messages as they are, or translate by status, field name and OAuth `error`
  code; they never need to parse message text.

## 16. Frontend integration

For FE-005 (API client and Pinia auth store):

1. **HTTP client.** Axios with `baseURL` = API host, `withCredentials: true`, `withXSRFToken: true`
   and header `Accept: application/json`. Axios then sends the `XSRF-TOKEN` cookie value as
   `X-XSRF-TOKEN` automatically.
2. **CSRF.** Before the first `POST`/`PATCH` of the page's lifetime, `await GET /sanctum/csrf-cookie`.
   On `419`, refresh it once and retry the request.
3. **Bootstrapping.** On app start call `GET /api/v1/auth/user`: `200` → signed in, `401` → guest.
   Store the customer in Pinia memory only; never persist credentials or the customer to
   `localStorage`.
4. **Register / login.** Post the payloads from section 5 and put `data` in the store. Show
   `errors.<field>[0]` next to fields on `422`.
5. **Logout.** `POST /api/v1/auth/logout`, then clear the store (also on `401`).
6. **Route guards.** Browsing needs no account. Before checkout, require a signed-in customer and
   redirect guests to sign-in with the intended path; require `email_verified` before placing an order.
7. **Social buttons.** `window.location.assign(API + '/api/v1/auth/social/' + provider +
   '/redirect?intended=' + encodeURIComponent(path) + '&remember=1')`. Never call it with XHR.
8. **`/auth/social/callback` page.** Read `status`, `provider`, `intended`, `new_account`, `error`
   (section 10). On success refetch `/auth/user` and navigate to `intended` (prompting for `name` or
   `phone` first if missing). On error show the message for `error`.
9. **`/email-verified` page.** Read `status` (`verified`, `already-verified`, `expired`, `invalid`);
   if signed in, refetch `/auth/user`.
10. **`/reset-password` page.** Read `token` and `email` from the URL **fragment**, clear the
    fragment, post to `/api/v1/auth/reset-password`, then send the customer to sign-in.
11. **Language.** Send `Accept-Language: bg` or `en` matching the UI language.
12. **Contract source.** Use this document and `docs/api/openapi.yaml`. `docs/openapi.json`
    (Scramble export, `bin/sync-api-spec`) only lists implemented routes, so contract-only endpoints
    appear there only once their ticket ships.
