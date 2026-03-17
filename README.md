# lti-ox6q — LTI 1.3 Learning Tool (Oxford Canvas)

An LTI 1.3 tool for Oxford Canvas that lets instructors create AI-generated learning apps and structured questions, which students can attempt inside a sandboxed iframe. State is persisted per-user per-app.

## Architecture

```
Canvas (lti-dev) → Oxford Tool Support → Frontend → Backend API
                                                       └── MariaDB
```

- **Canvas instance**: `lti-dev.canvas.ox.ac.uk`
- **Tool Support** (OIDC proxy): `tools-dev.canvas.ox.ac.uk` — passes through the Canvas JWT without re-signing
- **Backend**: Laravel 12 (PHP 8.2), stateless JWT auth, MariaDB
- **Frontend**: React 18 + Vite, [@oxctl/ui-lti](https://github.com/oxctl/ui-lti) for LTI token handling, [@instructure/ui](https://instructure.design) components

## LTI launch flow

1. Canvas → Tool Support (OIDC login/auth)
2. Tool Support → Frontend (`?token=xxx&server=...`)
3. `LtiTokenRetriever` fetches the Canvas JWT from Tool Support
4. Frontend → `POST /api/auth/bootstrap` with Canvas JWT
5. Backend validates the JWT (signature via JWKS, iss, aud, exp, nonce, replay protection) and issues a short-lived local JWT
6. Frontend uses local JWT for all subsequent API calls

## Features

- **Open interaction apps** — AI-generated HTML/CSS/JS mini-apps running in a sandboxed iframe with a `window.sdk` API for state and notifications
- **Structured question sets** — AI-generated multiple choice, matching, fill-in-blank, ordering, and numeric questions
- **Deep linking** — instructors create/edit apps via the Canvas rich content editor
- **State persistence** — per-user per-app state stored in MariaDB; instructors can view state summaries and reset student state on revision
- **AI generation** — OpenAI-backed generation and revision of both app types

## Repo structure

```
.github/workflows/build.yml   GitHub Actions: builds prod images → GHCR
backend/
  Dockerfile                  Dev image (php:8.2-cli + artisan serve)
  Dockerfile.prod             Prod image (php:8.2-fpm + nginx + supervisord)
  app/                        Laravel source (committed)
  docker/
    entrypoint.sh             Dev container startup (env setup, migrations)
    entrypoint.prod.sh        Prod container startup
    nginx.conf                nginx config for prod backend container
    supervisord.conf          Runs nginx + php-fpm in prod container
frontend/
  Dockerfile                  Dev image (Vite dev server)
  Dockerfile.prod             Prod image (Vite build → nginx static serve)
  docker/
    nginx-frontend.conf       nginx config for prod frontend container
  src/
    App.jsx                   Main application
    components/               Runner, StructuredRunner, DeepLinkForm, etc.
docker-compose.yml            Local dev (backend + frontend + mariadb)
docker-compose.prod.yml       Reference config for production server
```

## Running locally

**Prerequisites**: Docker Desktop

```bash
docker compose up --build
```

- Frontend: http://localhost:5173
- Backend API: http://localhost:8000
- MariaDB: localhost:3306 (user: `lti_user`, password: `secret`, db: `lti_ox6q`)

The backend container handles `.env` setup, dependency installation, and migrations automatically on first start. Subsequent restarts reuse the existing `.env` without overwriting manually-set values (except DB connection and Tool Support credentials, which are driven from `docker-compose.yml`).

### Dev environment variables (docker-compose.yml)

Key environment variables passed to the backend container:

| Variable | Purpose |
|----------|---------|
| `DB_*` | MariaDB connection (host/user/pass/db) |
| `TOOLSUPPORT_JWKS_URL` | JWKS endpoint for Canvas JWT signature verification |
| `TOOLSUPPORT_JWT_ISS` | Expected `iss` claim (`https://lti-dev.canvas.ox.ac.uk`) |
| `TOOLSUPPORT_JWT_AUD` | Expected `aud` claim (tool registration client ID) |
| `TOOLSUPPORT_SKIP_SIGNATURE` | Set `true` to bypass JWKS verification (dev without Canvas only) |

Secrets that live only in `backend/app/.env` (not in docker-compose):

| Variable | Purpose |
|----------|---------|
| `LOCAL_JWT_SECRET` | Signs local access tokens |
| `OPENAI_API_KEY` | OpenAI API key for app/question generation |

## Production deployment

Production images are built automatically by GitHub Actions on every push to `main` and pushed to GHCR.

### GitHub Actions variables required

Set these in **Repository → Settings → Variables → Actions**:

| Variable | Value |
|----------|-------|
| `PRODUCTION_URL` | `https://serverdomain/ox6q` |
| `PRODUCTION_BASE` | `/ox6q/` |

### Images

```
ghcr.io/OWNER/lti-ox6q/backend:latest
ghcr.io/OWNER/lti-ox6q/frontend:latest
```

### Server setup

See `docker-compose.prod.yml` for the full reference configuration. In summary:

1. Create `/opt/lti-ox6q/` on the server
2. Copy `docker-compose.prod.yml` there as `docker-compose.yml`
3. Create `/opt/lti-ox6q/.env.prod` (chmod 600) containing:
   ```
   APP_KEY=base64:...
   DB_PASSWORD=...
   LOCAL_JWT_SECRET=...
   OPENAI_API_KEY=...
   TOOLSUPPORT_JWKS_URL=...
   TOOLSUPPORT_JWT_ISS=...
   TOOLSUPPORT_JWT_AUD=...
   ```
4. Authenticate with GHCR: `echo $PAT | docker login ghcr.io -u USERNAME --password-stdin`
5. `docker compose pull && docker compose up -d`

### Apache vhost (subdirectory deployment)

The tool is served from `/ox6q` on an existing vhost. The order of `ProxyPass` rules matters — API must come before the frontend catch-all:

```apache
ProxyPreserveHost On
RequestHeader set X-Forwarded-Proto "https"

# Backend API
ProxyPass        /ox6q/api/  http://127.0.0.1:8000/api/
ProxyPassReverse /ox6q/api/  http://127.0.0.1:8000/api/

# Frontend (static files + SPA)
ProxyPass        /ox6q/      http://127.0.0.1:8001/
ProxyPassReverse /ox6q/      http://127.0.0.1:8001/
```

Requires `mod_proxy` and `mod_proxy_http` (`a2enmod proxy proxy_http`).

## Key technical notes

- **JWT verification**: The backend reads `TOOLSUPPORT_SKIP_SIGNATURE` using `filter_var(..., FILTER_VALIDATE_BOOLEAN)` — PHP casts env `true` to `"1"`, not `"true"`, so string comparison would fail
- **Nonce + JTI replay protection**: Bootstrap endpoint stores nonces and JTIs in the Laravel file cache for 10 minutes
- **No Laravel sessions**: Fully stateless — all auth is via short-lived local JWTs (`LOCAL_JWT_EXPIRES_IN`, default 1800s)
- **Vite base path**: `VITE_BASE_PATH=/ox6q/` is injected at build time for the subdirectory deployment; defaults to `/` so local dev is unaffected
