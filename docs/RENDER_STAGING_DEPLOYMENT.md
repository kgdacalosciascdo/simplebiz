# SimpleBIZ Render Staging Deployment

Updated: 4 August 2026

This guide prepares the existing SimpleBIZ Laravel and React applications for a Render staging deployment. It does not make SimpleBIZ production-ready and does not change any business module or workflow.

## Architecture

The root `render.yaml` defines:

- `simplebiz-staging-api`: a Docker web service running Laravel 12, PHP-FPM, and Nginx from `backend/`.
- `simplebiz-staging-web`: a Render Static Site building the existing React/Vite application from `web/`.
- `simplebiz-staging-db`: a Render PostgreSQL database in Singapore.

The Blueprint uses Render's root-directory-relative settings for the Dockerfile, Docker context, build commands, migration command, and static publish directory. See the [Render Blueprint specification](https://render.com/docs/blueprint-spec) and [Render monorepo guidance](https://render.com/docs/monorepo-support) when reviewing or changing the Blueprint.

The checked-in staging branch is `staging`. Change the `branch` values in `render.yaml` if the repository uses a different deployment branch before creating the Blueprint.

## Create the Blueprint

1. Push the repository to the selected Git provider and create or select the `staging` branch.
2. In Render, create a new Blueprint and select this repository and branch.
3. Review the three resources before applying the Blueprint.
4. Keep the backend and database in Singapore. Static Sites are globally served and do not use a region setting.
5. Review the `free` plans. They are suitable only for temporary staging. Render Free web services have ephemeral filesystems, and Free Postgres databases expire after 30 days and have no backups. Upgrade the plans in the Render Dashboard when the staging environment must persist longer or hold important data. See [Render's free-instance limitations](https://render.com/docs/free).

No persistent disk is configured. This is intentional and keeps the Blueprint from silently selecting a paid service.

## Required secrets and environment values

Enter these values when Render prompts for Blueprint secrets:

- `APP_KEY`: generate locally with `php artisan key:generate --show` from `backend/`, then enter the result in Render. Do not commit or print it in logs.

The Blueprint supplies the database connection from the Render Postgres internal connection string and derives the public backend/frontend URLs from Render service properties. Do not add database passwords, tokens, object-storage credentials, or mail credentials to `render.yaml`.

The backend uses:

- `DB_CONNECTION=pgsql`
- `DB_URL` from `simplebiz-staging-db`
- `APP_ENV=production` and `APP_DEBUG=false`
- `LOG_CHANNEL=stderr` and `LOG_LEVEL=info`
- `CORS_ALLOWED_ORIGINS` and `FRONTEND_URL` from the exact static-site origin
- `FILESYSTEM_DISK=local` for the initial temporary staging choice
- `QUEUE_CONNECTION=sync` because the current repository has no implemented queue worker requirement

The static site receives the backend's `RENDER_EXTERNAL_URL` as a build-only value and builds `VITE_API_URL` as `<backend-public-url>/api/v1`.

The Blueprint pins the static-site build to Node `24.14.1`, Render's current default documented for newly created services. Local validation currently runs on Node `24.17.0`; the project has no application-level Node engine restriction, and the installed dependency engines require Node 24 or newer.

## Authentication, CORS, and HTTPS

Authentication remains the existing Sanctum bearer-token flow. The React client stores the access token locally and sends it as an `Authorization: Bearer` header. This deployment does not convert the application to cookie-based Sanctum authentication.

Laravel's CORS middleware is enabled for `api/*` and `/up`. Only the comma-separated origins in `CORS_ALLOWED_ORIGINS` are allowed; credentialed wildcard CORS is not enabled. `X-Correlation-ID` remains exposed to the frontend.

Render's forwarded proxy headers are trusted so Laravel can correctly detect the public HTTPS request. The container also passes the forwarded protocol to PHP-FPM. `SESSION_SECURE_COOKIE=true` is set for staging's secure session cookies, although API authentication itself remains bearer-token based.

The health check is:

```text
https://<backend-service>.onrender.com/api/v1/health
```

The response contains only service status, environment name, and timestamp; it does not expose secrets or database configuration.

## Migration and setup behavior

Every backend deploy runs this Render pre-deploy command before the web process starts:

```bash
php artisan migrate --force
```

Migrations are forward-only. The deployment does not run seeders, create demo data, or bootstrap a company automatically. After the first successful deploy, open the frontend and complete the existing one-time SimpleBIZ company setup flow.

Never run these commands against staging:

```bash
php artisan migrate:fresh
php artisan db:wipe
```

Future schema changes must be added as a new forward-only migration, tested locally against PostgreSQL, and deployed through the normal staging branch flow. Do not edit an already-applied migration to change staging data.

## Attachments and evidence storage

Evidence uploads remain private and continue to require authenticated, company-scoped download authorization. The attachment service now honors `FILESYSTEM_DISK` and records the selected disk with each attachment.

The Blueprint initially uses private local storage for temporary staging. Render service filesystems are ephemeral: files can disappear after restart, redeploy, or free-service spin-down. Do not treat this setting as durable document storage.

Available staging choices:

1. Preferred: configure an S3-compatible private bucket and set `FILESYSTEM_DISK=s3` plus the required `AWS_*` credentials in the Render Dashboard.
2. Paid option: attach a Render persistent disk to the backend service and use the private local disk, after confirming the selected service supports persistent disks.
3. Temporary testing only: keep `FILESYSTEM_DISK=local` and accept file loss on restart/redeploy.

No upload is reported as durable when local ephemeral storage is selected, and no object-storage credential is required to build the application.

## Local versus staging

Local development continues to use the existing `backend/.env` and local PostgreSQL configuration. The local frontend fallback remains `http://localhost:8000/api/v1` only in development. Production builds require `VITE_API_URL` and do not embed a localhost fallback.

Staging uses the Render internal Postgres connection URL, the public backend URL for the frontend API, exact-origin CORS, and the existing company-isolated bearer-token API.

## Deployments, rollback, and troubleshooting

Both services auto-deploy commits pushed to `staging`. A backend migration is applied before the new backend process starts. Review the backend deploy log first when a deploy fails, then the pre-deploy migration output, then the static-site build log.

For a bad application deploy, use Render's service rollback to the last known-good deploy. A rollback does not automatically undo a database migration; database changes must remain backward-compatible or have an explicitly designed forward-only recovery migration.

For common checks:

- `502` or failed health check: inspect the backend container logs and confirm Nginx is listening on Render's `PORT`.
- CORS errors: confirm the static site's exact `RENDER_EXTERNAL_URL` is present in backend `CORS_ALLOWED_ORIGINS`.
- API connection errors: confirm the static-site build used the backend service URL and that the URL ends with `/api/v1` exactly once.
- Database errors: confirm the database is available and inspect the `php artisan migrate --force` pre-deploy output.
- Missing evidence: check the selected filesystem disk and remember that local staging storage is ephemeral.

## Validation commands

Run from the repository after installing dependencies:

```powershell
Push-Location backend
php artisan config:clear
php artisan route:list
php artisan test
vendor/bin/pint --test
Pop-Location

Push-Location web
npm run lint
npm run test -- --run
npm run build
Pop-Location
```

When Docker is available:

```powershell
docker build -t simplebiz-staging-api ./backend
```

Run the image only with a safe local test `.env` or explicit non-production environment variables. Do not copy a real `.env` or credentials into the image.

## Known limitations

- This is staging preparation, not a production-readiness declaration.
- Free Render web services can sleep and have ephemeral filesystems.
- Free Render Postgres is limited, has no backups, and expires after 30 days.
- Local attachment storage is temporary unless an S3-compatible disk or supported persistent disk is configured.
- There is no queue worker service because the current application has no queued job requirement; staging uses the synchronous queue connection.
- Mail delivery and all business-module scope remain governed by the existing implementation status and MDS documentation.
