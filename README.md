# lti-ox6q (Tool Support JWT bootstrap)

This proof-of-concept demonstrates:

1) Paste a Tool Support launch JWT (from a previous LTI launch)
2) Laravel verifies it once and issues a **local short-lived access token**
3) React uses the local token to fetch a **dummy learning app package** from the DB
4) The dummy app runs in a **sandboxed iframe**, calling back to the parent via an SDK (`postMessage`)
5) State is persisted via the API, so the student can resume

## Repo structure

- `frontend/` React (Vite)
- `backend/` Laravel API (created automatically on first run), plus prototype overrides

## Prerequisites

- Docker Desktop + Docker Compose
- VSCode (optional but nice)

### Windows 11 notes

You *can* do this on Windows 11. If Docker Desktop has been flaky for you before, these usually help:

- Use **WSL2 backend** in Docker Desktop
- Keep the project directory **inside the WSL filesystem** (e.g. `\\wsl$\Ubuntu\home\<you>\projects\lti-ox6q`) rather than on `C:`
- Exclude the project folder from aggressive antivirus scanning

If you already know your Docker setup on Windows is unreliable, your MacBook Air is often the lower-friction option.

## Running the prototype

From the repo root:

```bash
docker compose up --build
```

Then open:

- Frontend: `http://localhost:5173`
- Backend health: `http://localhost:8000/api/health`

On first run, the backend container will create a fresh Laravel project in `backend/` and apply the prototype override files.

## JWT verification settings

In `docker-compose.yml` (backend service env vars):

- `TOOLSUPPORT_JWKS_URL` — **recommended**. JWKS URL for the Tool Support JWT issuer.
- `TOOLSUPPORT_JWT_ISS` — optional strict issuer check.
- `TOOLSUPPORT_JWT_AUD` — optional strict audience check.
- `TOOLSUPPORT_SKIP_SIGNATURE` — if set to `"true"`, the backend will *skip signature verification* (claims-only). Useful to get the prototype running if you don't yet have JWKS.

## What to do in the UI

1) Paste a Tool Support JWT into the textbox
2) Click **Bootstrap** (should return a local access token)
3) Click **Load dummy app package**
4) Use the dummy app's **Save** button
5) Refresh the page, load + run again, and confirm the count resumes

## Where the interesting backend code lives

Once Laravel is generated (first run), the key files are:

- `routes/api.php`
- `app/Http/Controllers/AuthBootstrapController.php`
- `app/Services/ToolSupportJwtVerifier.php`
- `app/Http/Middleware/LocalJwtAuth.php`
- `app/Http/Controllers/AppController.php`
- `database/migrations/*create_learning_apps*`
- `database/migrations/*create_app_states*`

## Next steps

- Replace the “paste JWT” UI with `LtiTokenRetriever` (`@oxctl/ui-lti`) in the React shell
- Swap the dummy app package for “LLM-generated code stored in DB”
- Tighten the iframe sandbox + CSP, and expand the SDK with the minimal capabilities you want to allow
