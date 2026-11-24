<!-- esoul-guidelines:begin tools -->
# Project guidelines - Tools

The commands below are the project's contract with developers, agents and CI.

## Versions

| Area | Tool | Version / source |
|---|---|---|
| Runtime | PHP | 8.5 |
| Framework | Laravel | 13 (exact version in `composer.lock`) |
| Commands and queries | `esoul-cz/laravel-cqrs` | see [cqrs.md](cqrs.md) |
| Node | Node.js | 26 |
| Node package manager | pnpm | 12 (the latest release, pinned in `package.json` `packageManager`) |
| Frontend build | Vite | 8 |
| PHP quality | Pint (`laravel` preset), Larastan (PHPStan level 10 on `app/`), PHPUnit 13 with Paratest | `pint.json`, `phpstan.neon`, `phpunit.xml` |
| Test database | SQLite `:memory:` | `phpunit.xml`, see [testing.md](testing.md#database-in-tests) |
| Runtime and delivery | Docker (base image `php-fpm-laravel:8.5`), Jenkins, Komodo | see [deployment.md](deployment.md) |
| Agent tooling | Laravel Boost (guidelines, skills, MCP), skills in `.agents/skills` | `AGENTS.md`, `.ai/guidelines/`, `skills-lock.json` |

`composer.json`, `package.json` and their lockfiles are authoritative for installed versions. Use documentation that matches the installed majors (Laravel, PHPUnit, Tailwind and the other installed packages), even when a skill links to a newer one; check the installed source before concluding that a feature does not exist. Adding or upgrading a dependency needs the maintainer's approval.

<!-- from: package/mutation-testing -->
Mutation testing adds this row:

| Area | Tool | Source |
|---|---|---|
| Mutation testing | Infection (local, optional) | `infection.json5` |

<!-- from: package/laravel-inertia -->
An Inertia project adds these rows:

| Area | Tool | Version / source |
|---|---|---|
| User interface | Inertia 3, Vue 3, TypeScript, Tailwind CSS 4, shadcn-vue, Laravel Wayfinder | see [frontend.md](frontend.md) |
| Frontend quality | ESLint (flat config), Prettier (`printWidth` 150, `tabWidth` 4), TypeScript, Vitest | `eslint.config.js`, `.prettierrc`, `tsconfig*.json`, `vitest.config.ts` |

## Where tools run

The application runs only in the Docker stack ([deployment.md](deployment.md)). Application commands (migrations, seeders, generators, Tinker) run in the container:

```bash
docker compose exec laravel php artisan <command>
```

Lint, static analysis, formatting and the tests MAY run on the host (PHP, Composer, Node and pnpm installed) or in the container. The tests use SQLite `:memory:` and never need the database service. A host-side run exports `OTEL_PHP_DISABLED_INSTRUMENTATIONS=all` when the OpenTelemetry PHP extension is installed; it has to be in the shell environment, because `.env` and `phpunit.xml` are read only after the autoloader has run.

A fresh checkout or git worktree has no `vendor/` and no `.env`:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

## Read-only verification

Run these before pushing. They never rewrite tracked files, and CI runs the same ones.

### PHP

```bash
composer phpstan  # Larastan (PHPStan level 10) over app/
composer cs       # pint --test
composer test     # php artisan test --parallel
```

### Guidelines

```bash
vendor/bin/esoul-guidelines check  # drift of the rendered guidelines, the AGENTS.md block and the lock; never writes
```

CI runs it as the stage `Guidelines check` of the shared pipeline, from the project's own `vendor/` (see [deployment.md](deployment.md#pipeline)); a failure is fixed with `esoul-guidelines sync` after the content was moved out of the managed block, not by editing the block.

### Narrow runs

```bash
php artisan test --parallel tests/Feature/<Area> tests/Unit/<Area>   # the touched area, before a commit
php artisan test --filter=<TestName>                                  # one behaviour while editing
```

The whole suite runs once on the code that will be pushed, not after every edit ([testing.md](testing.md#testing-workflow)); the test groups are in [testing.md](testing.md#test-groups).

### Frontend

```bash
pnpm build
```

<!-- from: package/laravel-inertia -->
The Inertia frontend adds these checks before `pnpm build`; they never rewrite tracked files, and CI runs the same ones:

```bash
pnpm eslint
pnpm format:check
pnpm tsc
pnpm test
```

- When the frontend uses Wayfinder, CI generates the route modules (`php artisan wayfinder:generate`, with `WAYFINDER_TYPES_PREGENERATED=true` set) before `pnpm tsc` and `pnpm build`; without them the typecheck fails on missing imports.

## Static analysis

- Larastan runs PHPStan at **level 10** over `app/`, with `bleedingEdge`. The baseline stays empty: a new error is fixed, not baselined.
- An ignore is the narrowest possible (one identifier, one path) with a comment explaining why.
- Test code MAY be analysed as well (`phpstan/phpstan-phpunit`); when it is, type the data a test reads instead of suppressing an error.

## Formatting and lint autofixes

```bash
vendor/bin/pint --dirty --format agent   # PHP files changed in the working tree
composer pint                            # the whole PHP tree
```

- `pint.json` is the PHP formatting contract: the `laravel` preset plus the project rules declared there, including `declare(strict_types=1)`. The project does not claim blanket PSR-12.
- Mutating commands are explicit developer actions. Run them deliberately, review the diff, then rerun the read-only checks. CI uses only the read-only commands (`composer cs`).

<!-- from: package/laravel-inertia -->
```bash
pnpm eslint:fix
pnpm format
```

- Prettier uses `printWidth` 150 and `tabWidth` 4; ESLint uses the flat config (`eslint.config.js`). Do not add a second formatter or a legacy `.eslintrc`.
- CI uses only the read-only frontend commands of [Read-only verification](#read-only-verification): `pnpm eslint`, `pnpm format:check` and `pnpm tsc` in the JS static checks, `pnpm test` in the JS tests ([deployment.md](deployment.md#pipeline)).

## Tool caches

PHPStan, PHPUnit, Pint, ESLint, Prettier and Vitest keep their caches in the gitignored `tmp/` directory, which Jenkins persists between builds. Delete `tmp/` if a cached result looks stale.

<!-- from: package/mutation-testing -->
On LSR, Infection's cache is `temp/tools/infection`, next to the other tool caches.

## Code generators

- Commands, queries and handlers: `make:cqrs-command`, `make:cqrs-query`, always with `--handler` and `--return` ([cqrs.md](cqrs.md#creating-commands-and-queries)).
- Everything else Laravel generates (`make:model`, `make:migration`, `make:policy`, `make:request`, `make:test`) is moved into its layer and module before committing ([architecture.md](architecture.md)).

<!-- from: package/laravel-inertia -->
- Wayfinder route and action modules are generated by the Vite plugin during `pnpm dev` and `pnpm build`; run `php artisan wayfinder:generate --with-form` when the plugin is unavailable. Generated files are never edited.

## Laravel Boost

- Laravel Boost, when installed, generates its own block in `AGENTS.md` between `<laravel-boost-guidelines>` tags from `.ai/guidelines/` and the installed packages. **Never edit inside that block**; `php artisan boost:update` rewrites it.
- The project guidelines live in `docs/project_guidelines/` and are the authority wherever they conflict with generated Boost text. In particular, Boost's advice to deploy with Laravel Cloud does not apply ([deployment.md](deployment.md)).
- Skills live in `.agents/skills/` and are committed; `.claude/skills/<name>` links to them.
- `boost.json` and `.mcp.json` are gitignored: every developer runs `php artisan boost:install` once after cloning. **`composer update` runs `php artisan boost:update`** (the `post-update-cmd` script), which refreshes the guidelines and skills; commit the result only when updating them was the point of the work.
- Boost registers its commands only with `APP_ENV=local` or `APP_DEBUG=true`, so a fresh checkout needs its `.env` first. Its database tools need the database reachable, so the MCP server runs in the container (`docker compose exec -T laravel php artisan boost:mcp`).

## PHP conventions

- When chaining from a newly instantiated class, write `new Foo()->bar()`; the only parentheses are the constructor call.
- Long-running queue workers do not clear `once()` between jobs; keep job-dependent memoization on job-local objects.

## Documentation checks

When the project keeps application documentation that is built to PDF:

```bash
docs/documentation validate
docs/documentation build
```

The build requires Docker; PDFs are written to the gitignored `docs/pdf/` ([documentation.md](documentation.md#generated-documentation)).

## Known pitfalls

- A migration that passes on SQLite can fail on MariaDB or MySQL: a `timestamp()` column without `nullable()` or a default fails under strict mode (error 1067), and `ONLY_FULL_GROUP_BY` rejects a `SELECT` expression that differs from the `GROUP BY` one. Run migrations and queries on the production engine in the `db-engine` group ([testing.md](testing.md)); identifier length is covered in [architecture.md](architecture.md#migrations).
- PHPStan reports "no files to analyse" on a new project whose `app/` is still empty. Guard that step in the pipeline; do not disable the analysis.

## Mutation testing (local, optional)

<!-- from: package/mutation-testing -->
```bash
composer mutation
```

`composer mutation` runs Infection on your machine before you push. It is optional, never a CI gate and sets no score threshold. How to read the output and handle escaped mutants is described in the mutation testing section of [testing.md](testing.md#mutation-testing).

> Local additions go below this managed block. Content outside the block is preserved when the guidelines are synced.
<!-- esoul-guidelines:end tools -->
