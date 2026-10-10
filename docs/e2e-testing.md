# Isolated API instance for frontend end-to-end tests

The frontend (aytos24-app) runs live API integration tests — `npm run test:live` and
`npm run test:e2e:live` — against a separate API instance so they never touch development data.

| | Development | e2e |
| --- | --- | --- |
| URL | http://localhost:8000 | http://localhost:8001 |
| Services | `app`, `nginx` | `app-e2e`, `nginx-e2e` (`compose.e2e.yaml`) |
| Database | `laravel` | `e2e` (same MySQL server, dedicated database) |
| `APP_ENV` | `local` | `testing` |
| Rate limiting | 60/min per IP | none (`CACHE_STORE=array`) |
| CORS origins | `CORS_ALLOWED_ORIGINS` (default `http://localhost:5173`) | `E2E_CORS_ALLOWED_ORIGINS` (default `http://localhost:5175`) |

## Start and seed

```bash
bin/e2e-env
```

1. Starts `mysql`, `app-e2e` and `nginx-e2e` (the normal stack is unaffected).
2. Creates the `e2e` database if missing.
3. Runs `migrate:fresh` + `DevelopmentFoodSeeder` **only** if the container reports `DB_DATABASE=e2e`
   and `APP_ENV=testing` — otherwise it refuses. This is the only destructive step and it is limited to
   the dedicated `e2e` database.
4. Waits until `GET /api/v1/meals/today` responds.

Seed data is relative to the current date in Europe/Sofia, so re-run it (the frontend's global setup does
this automatically) whenever the date changes. `docs/development-data.md` lists the deliberate edge cases
(sold out, drafts, inactive restaurant/meal/category) the tests assert on.

Stop it with `docker compose -f compose.yaml -f compose.e2e.yaml stop app-e2e nginx-e2e`.

## Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `E2E_APP_PORT` | `8001` | Host port of the e2e API. |
| `E2E_CORS_ALLOWED_ORIGINS` | `http://localhost:5175` | Frontend origin(s) of the e2e suite. |

No backend code, framework version or dependency was changed for this setup — only `compose.e2e.yaml`,
`docker/nginx/e2e.conf`, `bin/e2e-env` and `config/cors.php` (see `docs/cors.md`).
