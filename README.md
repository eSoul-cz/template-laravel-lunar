# Application name

Replace this title and the sentence below with what the application does.

## Getting started

1. Clone: `git clone git@github.com:eSoul-cz/<repository>.git`.
2. Agent skills: `npx skills experimental_install` restores the set pinned in `skills-lock.json`. Optional personal layers (installed once per user, not part of the project): `npx @esoul-cz/skills install project helpers`; `npx @esoul-cz/skills layers` lists them.
3. Local environment: [Setup](#setup) (`cp .env.example .env`, `docker compose up -d --build`).
4. Access, asked from the project lead or devops: the Freelo project (`CONTEXT.md` › Freelo), the Jenkins job (its `Jenkins` check runs on every pull request) and the project's vault in 1Password (secrets and staging logins live there, never in Git). Then read `AGENTS.md` and `CONTEXT.md`.

## Requirements

- Docker with Compose (OrbStack or Docker Desktop). The application runs only in the Docker stack; `artisan serve` on the host is not supported.
- PHP 8.5, Composer, Node 26 and pnpm 12 on the host are optional: only for running the linters, static analysis and the tests outside the container.
- Access to the eSoul container registry (`rg.fr-par.scw.cloud`) to pull the PHP base image.

## Setup

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec laravel php artisan key:generate   # only when APP_KEY in .env is empty
```

The first start installs the Composer and pnpm dependencies, waits for the database, runs the migrations and starts php-fpm, the queue listener, the scheduler and Vite. Follow it with `docker compose logs -f laravel`.
`composer create-project` already copies `.env` and generates the key.

## Ports

| Port | Service | Setting |
|---|---|---|
| 8080 | The application (nginx) | `APP_PORT` |
| 5173 | Vite dev server | `VITE_PORT` |
| 8025 | Mailpit inbox: every mail the application sends | `MAILPIT_UI_PORT` |
| 1025 | Mailpit SMTP | `MAIL_PORT` |
| 3306 | MariaDB, for a client on the host | `FORWARD_DB_PORT` |

## Commands

Application commands run in the container:

```bash
docker compose exec laravel php artisan <command>
docker compose exec laravel php artisan migrate
docker compose exec laravel php artisan db:seed       # local demo data (DemoSeeder)
```

Checks, in the container or on the host; CI runs the same ones:

```bash
composer phpstan   # Larastan, level 10
composer cs        # Pint, check only (`composer pint` fixes)
composer test      # PHPUnit in parallel, SQLite in memory
composer mutation  # Infection on the lines changed against main (local, never in CI)
pnpm build         # Vite production build
```

## Configuration

The settings are in `.env` (local) and in the stack's environment (deployed). A new variable is added to `.env.example` with a placeholder and named in the pull request's deploy notes.

| Variable | Meaning |
|---|---|
| `APP_PORT`, `VITE_PORT`, `MAILPIT_UI_PORT`, `FORWARD_DB_PORT`, `WWWUSER`, `WWWGROUP` | The local stack only (`docker-compose.yml`) |
| `RUN_MIGRATIONS`, `RUN_SCHEDULER`, `RUN_QUEUE_WORKER` | What the production container starts, each exactly `true` or `false`. Exactly one container of a stack runs the scheduler |
| `TRUSTED_PROXIES` | Addresses or CIDR ranges of the reverse proxies whose forwarded headers are trusted. Empty trusts none, which is right behind the host NGINX. Never `*` |
| `MONEY_DEFAULT_CURRENCY` | ISO code used by `App\Infrastructure\Casts\MoneyCast` when a row has no currency |
| `OTEL_*` | OpenTelemetry export, see below |

## OpenTelemetry

Every deployed application exports traces, logs and metrics over OTLP (gRPC) to the host collector. The base image has the `opentelemetry` PHP extension; the template ships only the `OTEL_*` placeholders in `.env.example` and keeps `OTEL_PHP_AUTOLOAD_ENABLED=false`, so nothing is exported yet.
To switch it on, a maintainer approves and adds the SDK, the OTLP exporter and the Laravel auto-instrumentation packages, sets `OTEL_PHP_AUTOLOAD_ENABLED=true` and a real `OTEL_SERVICE_NAME`, and the stack's environment names the collector (`OTEL_EXPORTER_OTLP_ENDPOINT`).
Host-side test runs export `OTEL_PHP_DISABLED_INSTRUMENTATIONS=all` when the extension is installed.

## Production image

`docker/production/Dockerfile` builds the release image: php-fpm on port 9000 under Supervisor with the optional queue worker and scheduler, and no web server (the host nginx reaches php-fpm over FastCGI). Its `HEALTHCHECK` fails when a Supervisor program that should run is not `RUNNING` or when a FastCGI request for `/up` does not answer `200`.

## Troubleshooting

- **`docker compose up` hangs at "Waiting for the database"**: the MariaDB container is still initialising on the first start; the entrypoint retries for two minutes. `docker compose logs mariadb` shows the reason when it does not come up.
- **Port already in use**: change `APP_PORT`, `VITE_PORT`, `MAILPIT_UI_PORT` or `FORWARD_DB_PORT` in `.env`.
- **Files written by the container belong to root on Linux**: set `WWWUSER` and `WWWGROUP` to `id -u` and `id -g` in `.env` and rebuild (`docker compose up -d --build`). Docker Desktop and OrbStack ignore them.
- **Missing PHP extension or base image**: the extension belongs to the shared base image, not to this repository's Dockerfile.

## Frontend (Inertia and Vue)

Run the read-only checks before pushing; CI runs the same ones:

- `pnpm eslint` (`pnpm eslint:fix` fixes) and `pnpm format:check` (`pnpm format` fixes)
- `pnpm tsc` type-checks the Vue and TypeScript sources
- `pnpm test` runs Vitest; `pnpm build` is the production Vite build

The Wayfinder route and action files are generated by Vite (`pnpm dev`, `pnpm build`) and by `php artisan wayfinder:generate --with-form`; they are not committed. The dev server runs inside the `laravel` container and is reached on `VITE_PORT`.

## Lunar

The shop runs on Lunar 1.x with its own admin panel (Filament 4) at `/admin`, registered by `App\Domain\Providers\Filament\LunarPanelProvider`.

- First setup, once the stack is up: `docker compose exec laravel php artisan migrate`, then `docker compose exec laravel php artisan lunar:install` (default language, currency, channel, tax class and the first admin).
- Lunar's configuration is not published, except `config/lunar/cart.php`, which swaps Lunar's `CalculateLines` cart step for the project's integer-arithmetic one (`App\Infrastructure\Lunar\Cart\CalculateLines`: minor units, rounded half away from zero). `docker compose exec laravel php artisan vendor:publish --tag=lunar` publishes the rest when it needs changing and leaves that file alone.
- A currency the shop sells in must be a case of `App\Domain\ValueObjects\Currency`.
