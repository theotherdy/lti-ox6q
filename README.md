# lti-ox6q — Tool Support JWT bootstrap prototype

This repo is a proof-of-concept for:

1) Paste a Tool Support launch JWT (captured from a previous LTI launch)
2) Laravel verifies it once and issues a short-lived **local API access token**
3) React uses the local token to fetch a **dummy learning app package** from the DB
4) The dummy app runs in a **sandboxed iframe** and calls back to the parent via a tiny SDK (`postMessage`)
5) State is persisted via the API so the student can resume

## Repo structure

- `frontend/` React (Vite)
- `backend/` Docker image for the API runtime
- `backend/app/` Laravel API source (committed)
- `docker-compose.yml` local dev orchestration

## Run locally

```bash
docker compose up --build
