<!-- esoul-guidelines:begin deployment -->
# Project guidelines - Deployment

People and agents working on a project do not control deployment. This file is a **reference** for how eSoul applications are built and run, so that the code, the Dockerfile and the pull request fit the scheme. Only people with devops access deploy and change stacks, manifests and servers; what is written here about them is a fact of the scheme, not a task for project developers. Framework files add runtime detail to the sections of this file and MUST NOT contradict it. MUST, MUST NOT, SHOULD and MAY are used as in RFC 2119. **Target.** marks a rule the infrastructure does not yet meet; the gap is named.

<!-- from: framework/laravel -->
Laravel runtime detail added to the deployment scheme: how the application is built into images, how it runs and what the pipeline checks. The application runs only in eSoul's Docker images; building, releasing, stacks, shared services and secrets follow the scheme.

## How an application reaches production

- A **change to code or to a stack's configuration** reaches production only through a merged pull request and the project's CI pipeline in Jenkins. Publishing content from a CMS is not such a change: the editor's publish action starts the site's Jenkins job, which builds and deploys the already merged code with the new content, without a pull request (the publish flow in the stack's deployment guideline describes it). Deploying and changing stacks is done by people with devops access, through Jenkins and the manifest, never by hand in Komodo, on the server or with `docker` commands. The one exception is the rollback in an incident, which devops MAY do in Komodo ([Rollback](#environments-and-releases)). Agents never deploy outside Jenkins ([security-and-data.md](security-and-data.md#production-access)).
- A pull request runs the checks and tests (and, where the pipeline has it, a test build of the images); nothing is pushed or deployed.
- **The push build is fixed.** A new stage or check in it (a pipeline option that adds one, a script a pipeline step starts) needs an accepted ADR and a human's approval before it is added; no project, branch or agent batch adds one on its own authority. A check that needs the production database engine runs in the project's scheduled job ([testing.md](testing.md#test-groups)), never in the push build. The test stage stays within the [suite time budget](testing.md#suite-time-budget).
- A branch with a deploy rule builds multi-architecture images and pushes them to the company registry under `<registry>/app-images/`, tagged as the rule says (`latest` for `main`, [Environments and releases](#environments-and-releases)) and with every Git tag on the commit except `latest`, which only `main` pushes.
- A pipeline MAY smoke-test the pushed image before deploying (Jenkins capacity is limited, so it is not the default); where it does, a failed smoke test MUST stop the deploy that the pipeline starts. The image is then already in the registry, so a stack that Komodo redeploys on every new `latest` image is not protected by it (see the **Target.** in [Environments and releases](#environments-and-releases)).
- The deploy redeploys the project's Komodo stack, which pulls the tag and runs the stack's hooks. Which branch deploys to which stack is part of the project's pipeline configuration ([Environments and releases](#environments-and-releases)).
- A static site is released as a new directory and switched by one atomic link (`releases/<id>`, `current`). The previous releases stay on the server, so a rollback re-points the link.

## Stacks and manifests

- Every deployed stack is defined by one manifest, `apps/<client>/<name>.yaml`, in the infrastructure repository. A change to a stack is a change to its manifest.
- Applying a manifest writes the compose file, the Komodo stack definition and the NGINX site. **Applying is not a deploy:** it does not recreate running containers. Starting or updating containers is done only by a Komodo deploy, which Jenkins runs.
- A stack's difference from its shape goes into the manifest (`compose_extra`, `nginx.extra`, `env`) or into the project's own override (`project`). Nobody edits a rendered file or a Komodo setting that the manifest describes; the next apply replaces it.
- A manifest uses one of the shapes it offers (`laravel-fpm`, `lsr-roadrunner`, `node-http`, `static-release`). `custom` is used only when no shape fits, and the project records why.
- A second deployment of an application is a second manifest named `<name>-<environment>`. Stack names and domains are unique across servers.
- **Target.** Stacks still outside manifests are migrated one file at a time; until then their Komodo settings are changed in Komodo and recorded in the migration plan.

## Environments and releases

- A project starts with only the `main` branch, which is deployed during development to a **staging** environment. After the release `main` becomes **production**. A `staging` branch, deployed to the staging stack, MAY be added afterwards; it is project-specific and depends on the client's requirements (some clients do not pay for two deployments). The same lifecycle is in [workflow.md](workflow.md#branches).
- Every environment has its own stack, database, Komodo variables, buckets and secrets. Staging that holds real data is protected like production. A development stack MAY exist; it is not a release environment and holds no production data.
- The pipeline's deploy rules say which branch goes where, for example `main` to the staging stack before the release and to the production stack after it, and `staging` to the staging stack.
- Production runs the image built from `main`, tagged `latest`. Only `main` pushes `latest`; a second deploy branch pushes its own tag (for example `staging`), so a stack that Komodo redeploys on every new `latest` never runs staging code. No release-tag step is required.
- **Rollback.** The pipeline records what runs: after every successful `Deploy` it tags each image of the stack `deployed-<stack>` (the image that runs now) and moves the image that ran before to `previous-<stack>`. A rollback deploys `previous-<stack>` and is the one named exception to "never by hand in Komodo" ([How an application reaches production](#how-an-application-reaches-production)): in an incident, devops (the people with Komodo access) set the stack's `image_tag` to `previous-<stack>` and deploy the stack in Komodo. Afterwards the state is brought back in line: the hotfix issue records the rollback (when, which image, who), and the stack goes back to the manifest's `image_tag` with the next deploy of the fix through Jenkins. An `apply` resets `image_tag` to the manifest value but is not a deploy, so the rolled-back containers keep running until that deploy; until then `latest` still points at the broken image, so nobody redeploys the stack by hand at `latest`. Images pushed by a build whose `Deploy` failed are never `deployed-` or `previous-`, so they are never a rollback target; the tags move only in the pipeline, so a manual rollback leaves them as they were. A stack has the tags from its first deployment after the pipeline began to record them (`jenkins-libraries`, 2026-11-25); before that, the previous release is the image of the Git tag of the previous successful `main` build, or the digest the registry lists for the push before the current one, skipping builds that did not deploy. Do not rerun the old Jenkins build to roll back; it rebuilds the code and moves `latest` again. A static site is rolled back with `rollbackStaticSite`, which re-points `current` to the previous release without a build.
- Migrations run once per deployment, forward-only, and are safe while the previous release still serves requests. Only one container per stack runs them ([The production start entrypoint](#the-production-start-entrypoint) says how they run and what a failure does).
- **Target.** Some stacks are still redeployed by Komodo on every new `latest` image (auto-update); the pipeline deploys them instead once their stacks are managed.

<!-- from: framework/laravel -->
- Each environment has its own `APP_KEY` and OAuth redirects, next to the stack, database, buckets and secrets the scheme gives it.

## Monitoring a deployment

- A deployment to a real production environment MUST be monitored by the person who pushed or started it (merged the pull request that deploys, or started the Jenkins job). They stay with it until the new release serves, and check that the application still works in production: the health endpoint and the state of the stack's containers, the feature the change touched, the logs and the error alerts for new errors, and the output of the migrations from the start entrypoint, which is the only signal of a migration that failed ([The production start entrypoint](#the-production-start-entrypoint)).
- They are ready to roll back ([Rollback](#environments-and-releases)) or to ship a hotfix ([Hotfix](workflow.md#hotfix)), and decide which; the incident goes in the hotfix issue. Nobody starts a production deployment and walks away.
- A deployment to staging SHOULD be monitored the same way. It is recommended there, not required.

## The production start entrypoint

The production image starts through a `start` entrypoint, the script the container runs before it hands over to the server (Laravel `docker/production/start`, LSR `docker/startProd.sh`, the Node image's start script). Its stack detail is in the framework's deployment guideline; the rules are these.

- **It SHOULD be quick.** It starts the server (php-fpm, RoadRunner, Node) promptly. A heavy data task (a recalculation, a sync, a data cache warm-up, an import) runs in the background, as a one-shot Supervisor program or a background process (`&`), and never before the server starts. Migrations are the exception: they run before the application starts. Other steps the application needs before it can serve MAY stay in front of the server, for example caching configuration and routes, linking storage, preparing writable directories and the LSR `/prepare-runtime`.
- **Migrations run forward-only and non-destructively** (`php artisan migrate --force`, the LSR `app:install`; never a fresh, reset, rollback or wipe option), only in the container whose switch is on. **A failing migration MUST NOT block the deployment.** The container starts anyway and the entrypoint logs the failure loudly on standard error, naming the step and saying that the schema may be behind the code. The person who monitors the deployment ([Monitoring a deployment](#monitoring-a-deployment)) reads that output and reacts.
- **The start entrypoint MUST NOT run any seeder**: no `db:seed`, `migrate --seed`, install command that seeds, or seed step in a Dockerfile or a Komodo hook. Seeding is a deliberate act of a developer, done by hand in the container's terminal (`docker exec <container> php artisan db:seed --class=ProductionSeeder`, the stack's equivalent), where the output is in front of them.
- **In staging and production only deliberate seeders MAY run, and only by hand.** What matters is that the seeder is deliberate, not general random data to fill the database. The usual ones are the production seeder (the data every environment needs: idempotent, create-only, no account) and demo seeders (deliberate demo content, for example for a staging demo; the framework's deployment guideline gives the detail). The list is not exhaustive: a project MAY define other environments and seeder sets. A deliberate seeder SHOULD NOT depend on Faker: deployed images install no development dependencies, so Faker is usually missing there. It creates no account with credentials outside local development ([security-and-data.md](security-and-data.md#sessions-and-accounts)). A **general** seeder, the `DatabaseSeeder`-style seeding of synthetic development data, is for local development only: it MUST NOT run in staging or production, and it refuses to run there.

## Local development

- Local development runs in Docker. The project's `docker-compose.yml` MUST list every service the application needs: the database engine the application uses in production, a Valkey service when it uses a cache, a search node when it searches, and so on. Nothing the application needs is installed on the developer's host.
- Helper containers belong in the same file: `mailpit` to catch e-mail, and an S3-compatible container (a maintained MinIO image or an alternative) in place of object storage. Deployed applications use the company's Scaleway Object Storage, never a self-hosted object store.
- A web server container (NGINX) is allowed locally. It is not part of a deployed stack ([Reverse proxy and TLS](#reverse-proxy-and-tls)).
- The ports of support services MAY be bound to `127.0.0.1`; it is not required.
- Local credentials come from `.env`, copied from `.env.example`. Tests never use the development database ([testing.md](testing.md)).

<!-- from: framework/laravel -->
On Laravel, running the application on the host (`artisan serve`, Sail, a host database) is not supported. `docker-compose.yml` starts:

| Service | Purpose |
|---|---|
| `laravel` | php-fpm, queue worker, scheduler and Vite under Supervisor; the repository is bind-mounted |
| `nginx` | Serves the application on `${APP_PORT:-8080}` (local only) |
| database service | The production engine, named volume, health-checked |
| `mailpit` | Catches all outgoing e-mail. `.env.example` points the mail settings at it |

`.env.example` points the application at these services.

## Shared services

The servers run a few shared services, each applied from the infrastructure repository. An application uses them through its manifest and never adds a server of the same kind to its own stack.

### Database

- Application data is kept in a shared database server of the host. **MariaDB is the default.** **PostgreSQL** is for applications whose framework or libraries require it. A new application that needs PostgreSQL gets a login and a database on the shared server. Applications reach the server at `host.docker.internal`.
- Each application has its own database and its own login, declared in its manifest. A login owns only its database and never receives a superuser password.
- The password of a login is generated and kept in 1Password (vault `DevOps`, category Database); the stack receives it through a Komodo variable. It is never in a manifest, a rendered file or the repository.
- Schema changes go through migrations ([Environments and releases](#environments-and-releases)); no manual SQL in any real environment ([security-and-data.md](security-and-data.md#production-access)).
- **Target.** Several stacks still run their own PostgreSQL container; migrating them to the shared server is open.

### Cache

- A Redis-compatible cache is a Valkey service in the application's own stack (`valkey: {profile: cache}` or `{profile: kept}`), reachable only from that stack. No Redis-compatible server is shared between applications.
- `cache` holds caches and locks and MAY be emptied by a restart. `kept` holds sessions and queues and writes to disk. Code MUST NOT assume that a `cache` profile survives a restart.
- The application reads its host from `REDIS_HOST=valkey` (the `redis` alias also resolves).
- **Target.** Some stacks still use a KeyDB server (shared on the host, or a sidecar) and move to Valkey with their manifests.

### Search

- Full-text search uses the shared Elasticsearch node, and only when the application needs it. Each application has a user that can reach only the indices starting with its prefix.
- Indices MUST be rebuildable from the application's own database. An index that cannot be rebuilt does not go into the shared node.
- A different search engine needs an ADR first.

### Object storage

- Deployed applications store files in Scaleway Object Storage (S3 API, region `fr-par`). Buckets are per application and environment, named `esoul-<app>-<env>-<purpose>`.
- Public and private files use separate buckets. A private file is never served from a public bucket.
- The storage key is limited to Object Storage and kept in Komodo.
- **Target.** Buckets are created by hand before the first deploy and are not yet part of the backup.

## Reverse proxy and TLS

- Public traffic enters through the load balancer, which ends TLS and speaks HTTP to the host NGINX. NGINX runs only on the host; a deployed stack contains no web server container, and the application image contains no web server.
- Every PHP application, RoadRunner included, is reached from NGINX through FastCGI. Plain HTTP is proxied only in specific cases (some JavaScript-based projects, certain RoadRunner cases), and the manifest says so.
- Every public name of an application is listed in its manifest (`domains`, `redirects`) and resolves to the load balancer. The apply issues the certificates on the load balancer.
- The application trusts forwarded headers only from the proxy's addresses, never from `*`. The proxy sets the host, the scheme and the client address itself (as FastCGI parameters or `X-Forwarded-*` headers) and drops what a client sent.
- Production sites redirect plain HTTP to HTTPS and send HSTS.
- Metrics and status ports (RoadRunner's `2112` and `2114`, and the like) are never routed. Application admin interfaces are application routes behind authentication. The application's health endpoint ([Health endpoint](#health-endpoint)) is the one service endpoint that MAY be public.

<!-- from: framework/laravel -->
- php-fpm listens on port 9000. The host NGINX serves existing files of `public/` from a copy taken out of the image at deploy time, and a path that is not a file there is a route of the application.
- Trusted proxies are the `TRUSTED_PROXIES` setting.
- Body and buffer sizes match the application's limits. NGINX accepts a request body at least as large as the largest upload the application allows (the manifest's `nginx.client_max_body_size`, in step with PHP's `upload_max_filesize` and `post_max_size` and the validation limit), and FastCGI response headers of at least 32 KB (`fastcgi_buffer_size 32k; fastcgi_buffers 16 32k;` is the minimum; the rendered sites use 64k for the first buffer). The preload `Link` header of a large page is the reason ([frontend.md](frontend.md#vite-and-bundling)).

## Health endpoint

- An application served from a container exposes `GET /up`: `200` when it is ready and `503` when it is not. It does not depend on the session or on CSRF protection. The endpoint reveals only whether the application is up, so it MAY be public; the external check uses it.
- The image has a Docker `HEALTHCHECK` that requests it from inside the container. A crashed process is restarted by the container's supervisor; the healthcheck reports one that stays down.

<!-- from: framework/laravel -->
- The application exposes Laravel's built-in `/up` endpoint, which reports that the framework boots; it does not check dependencies. The image's `HEALTHCHECK` requests it.

## Configuration and secrets

- Plain settings are `env` in the manifest. Secrets are listed under `secrets` and become Komodo variables named `<STACK>_<NAME>`. Values MUST NOT appear in manifests, rendered files, Git or Komodo exports.
- Secrets have one home each: Komodo variables for a stack, 1Password for database logins and service accounts, the Ansible vault only for host-level secrets. A secret shared by several stacks is one Komodo variable that each stack references.
- **A new configuration variable:** the developer adds it to `.env.example` with a placeholder and names it in the pull request's deploy notes ([workflow.md](workflow.md#pull-requests)); devops add it to the manifest or to Komodo before the deploy.
- Secrets never go into build arguments, image layers or logs.
- Files on the server that hold secrets are readable only by root and by the container user. **Target.** Several `.env` files are currently readable by everyone.

<!-- from: framework/laravel -->
- The image carries `.env.example` as its `.env`, so a variable left out of the stack silently falls back to the local default; this is why a new variable is named in the pull request's deploy notes. The stack sets at least `APP_ENV`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL`, the database, mail and storage settings and **`SESSION_SECURE_COOKIE=true` in every deployed stack**; local `.env.example` does not set it, because local development runs over plain HTTP. The application clock (`app.timezone`) stays UTC.
- Production caches configuration, routes and views in the container start script (`config:cache`, `route:cache`, `view:cache`); code reads configuration only through `config()`, never `env()` outside `config/`.

## Observability

- Every application exports traces, logs and metrics over OpenTelemetry OTLP to the host collector (gRPC on `host.docker.internal:4317`), with the standard `OTEL_*` variables.
- Logs go to standard output and error. A log line names records by id and carries no personal data, amounts or file content ([security-and-data.md](security-and-data.md#personal-and-client-data)).
- Every production stack has an alert when its health check fails. **Target.** Coverage is not verified per stack.

## Backups

- The infrastructure backs up every application database and the application directories daily, away from the production host. Restores are tested.
- Data that must survive a container lives in the stack's run directory (a bind mount) or in a shared service, never only in a Docker volume ([Networks and access](#networks-and-access)).
- **Target.** Object-storage buckets are included in the backup; restore tests cover application directories and PostgreSQL.

## Networks and access

- Containers deployed by Komodo publish **no ports**. The containers of a stack talk on the stack's own network; shared containers (the search node, …) are on the server's shared network (`apps`).
- The host NGINX proxies to a container directly by name: the `hoster` service writes the containers' addresses and aliases into `/etc/hosts`. Docker-published ports bypass the host firewall, which is why none are published.
- Persistent state is a **bind mount** under the stack's run directory, `/data/apps/<client>/<app>`, and is covered by the backups. A named Docker volume is only for disposable data (caches, build output), which no restore depends on.
- Server access is by SSH key, from allowed addresses or over the tailnet. The tailnet access policy is kept in the infrastructure repository. **Target.** It is not yet applied.

## What not to introduce

- Laravel Cloud, Laravel Forge, Vapor or any hosted platform for production. Sail is not a production deployment.
- A Redis or KeyDB server shared between applications. A Redis-compatible store is a Valkey service in the application's own stack.
- A self-hosted object store in a deployed stack. Use Scaleway Object Storage through the S3 API; for local development any S3-compatible dev container is fine.
- A database server or a search engine owned by one application.
- A second reverse proxy (Traefik, Caddy) in front of or beside the host NGINX, without an ADR.
- A web server container in a deployed stack. (Locally it is allowed.)
- Hand edits in Komodo, on the server or in rendered files that the manifest manages. The only exception is the incident rollback ([Rollback](#environments-and-releases)).
- `docker system prune`, `docker volume prune`, `docker image prune -a` or `docker container prune` on a server: they remove data of stopped stacks.
- Production secrets or `.env` files with real values in Git, tickets, pull requests, logs or chat.

## Images

<!-- from: framework/laravel -->
- Every image starts from the shared eSoul base image `rg.fr-par.scw.cloud/esoul-starters/php-fpm-laravel:8.5`, which already provides the common PHP extensions. A missing extension is added to the base image, not installed ad hoc in a project Dockerfile (a temporary exception names the reason and the issue in the Dockerfile).
- **PHP runs as php-fpm and is reached through FastCGI on port 9000.** The host NGINX serves the files of `public/` and passes every other request to php-fpm ([Reverse proxy and TLS](#reverse-proxy-and-tls)). Octane (Swoole, RoadRunner, FrankenPHP) is not used and MUST NOT be added; code may rely on one process per request.
- One multi-stage `docker/production/Dockerfile` builds the release images (one target per image); `docker/dev/Dockerfile` builds the development image. **The Composer packages are their own stage**, rebuilt only when `composer.json` or `composer.lock` change.

| Image | Runs | Never runs |
|---|---|---|
| App image | php-fpm under Supervisor, with the queue worker and the scheduler switched on by environment | nginx |
| Worker image (optional target) | only queue workers | php-fpm, the scheduler, migrations |

- Environment variables decide what runs in a container (`RUN_MIGRATIONS`, `RUN_SCHEDULER`, `RUN_QUEUE_WORKER`). **Exactly one container in a stack runs the scheduler.**
- A queue worker needs the `pcntl` extension to enforce `--timeout`; without it a hanging job blocks its worker for good. Check it with `php -m` and have it added to the base image.
- Large design and source material kept in Git LFS stays outside the Docker build context (`.dockerignore`); the pipeline sets `GIT_LFS_SKIP_SMUDGE=1`.

## Pipeline

<!-- from: framework/laravel -->
The `Jenkinsfile` loads eSoul's shared Jenkins libraries (`dockerHelpers`, `testing`, `deploy` and `pipelines`) and calls the shared `laravelPipeline` step as its only statement; only what differs from the step's defaults is configured (image names and targets, prepare command, deploy rule, timeouts; an extra check only as the list below allows). The step reports the required `Jenkins` check on every branch and pull request. Its stages, in order:

1. **Checkout**, **Docker Login**, **Pull testing images**.
2. **Prepare**: `composer install`, an `.env` from `.env.example` with a fresh key, config cleared; a frontend layer adds its generation steps.
3. **Static checks**, in parallel: PHP static checks (Composer validation, Larastan, `pint --test`), **Dependency audit** (`composer audit` and, with a JavaScript frontend, `pnpm audit --audit-level critical`, which fails only on critical advisories; the target is the unfiltered audit of [dependencies.md](dependencies.md#install-hardening)), with a JavaScript frontend, JS static checks (install and the read-only checks of the frontend layer's tools guideline) and **Guidelines check** (`esoul-guidelines check` from the project's own `vendor/`, [tools.md](tools.md#guidelines); runs when the project has `esoul-guidelines.lock`, and `guidelinesCheck: false` in the pipeline configuration switches it off).
4. **Run Tests**, only after every static check passed, in parallel: **PHP Tests** (PHPUnit in parallel; on SQLite `:memory:` unless the pipeline's `testDatabase` option starts a throwaway database of the production engine), with a JavaScript frontend **JS tests** (the project's tests and the production build) and, on pull requests, **Test Docker Build** of every release image.
5. **Build and push image** on a branch with a deploy rule (`main` by default): multi-architecture images tagged as the deploy rule says (`latest` for `main`) and with any Git tag on the commit, pushed to `rg.fr-par.scw.cloud/app-images/`.
6. **Smoke test** (optional, the pipeline's `smoke` option, off by default because Jenkins capacity is limited): a Compose smoke test of the pushed images by their immutable reference, after `Build and push image` and before `Deploy`. The stage does not exist without the option; where it exists, a failure stops `Deploy`.
7. **Deploy**: the rule's Komodo stack is redeployed. A rule without a stack only publishes the images.

Keep the stage names stable: the required check is the multibranch job's `Jenkins` status. Mutation testing does not run in CI.

Jenkins is shared by every eSoul project, so build time matters:

- **The pipeline has timeouts.** A hung build is aborted instead of holding an executor until somebody notices: `laravelPipeline`'s `timeoutMinutes` sets the limits (`total`, `testing`, `images`), and a changed value has a comment saying why.
- A newer push to the same pull request aborts its running build.
- **A build runs what its change can affect** (the pipeline's `changeFilter`, on by default; its log says in `Checkout` what was compared and what is left out). A pull request that changes only documentation (`docs/`, Markdown at the top of the repository, `.github/`, agent and tool files) runs only the **Guidelines check**; one that changes only the backend runs no JavaScript stage (a change of the routes or of an HTTP class keeps the type check), one that changes only the frontend runs no PHP static check. A merge to a branch with a deploy rule runs every stage, unless it changes only documentation: then nothing is tested, no image is built and nothing is deployed. `Build Now` in Jenkins runs everything for a commit.
- A top-level directory or file the pipeline does not know (a `scripts/` directory, a data migration, a second package) makes every change to it run everything. Name it in the `Jenkinsfile` instead: `changeFilter: [areas: [php: [add: ['scripts/']]]]`. `changeFilter: false` is not the answer to an unknown path, and switching the filter off has a comment saying why.
- Push a coherent unit, and push review fixes together once they pass locally; every push starts a build. A red build is reproduced locally before its fix is pushed; never push to see whether CI agrees.
- When the multibranch job discovers both branches and pull requests, a commit with an open pull request can be built twice; building each commit once is a job setting, not a reason to skip stages in one of the two jobs.
- Integration tests follow [testing.md](testing.md#keeping-the-integration-run-short), and the test stage stays within the [suite time budget](testing.md#suite-time-budget).
- The release-image test build is the slowest stage; it runs on pull requests. It always uses the layer cache of the agent, and first asks the registry for the base images (`--pull`), so a pull request is built on the base the release will use. `testDockerBuild: [pull: false]` in the pipeline configuration switches that check off. A build without the cache is not available: `testDockerBuild.noCache` is deprecated and ignored.
- Paratest processes (and the worker count of a JS test runner, where there is one) match the Jenkins agent; a value of 1 has a comment saying why.
- `extraChecks` and any other stage added to `laravelPipeline` are new checks in the push build ([How an application reaches production](#how-an-application-reaches-production)): they need an accepted ADR and a human's approval first. The built-in `Guidelines check` stage is not one of them: it is part of the step, needs no ADR, and a project does not write it as an `extraChecks` entry. A check that needs the production engine (locking, collations, engine-specific SQL) is a `db-engine` test in the scheduled job, never an `extraChecks` entry.
- A separate scheduled Jenkins job runs what the push build does not (the `sandbox`, `quarantine` and `db-engine` groups on the production engine, [testing.md](testing.md#test-groups)). It does not report the required check, and every failure becomes an issue.

<!-- from: package/laravel-inertia -->
An Inertia project adds these details to the pipeline stages:

- **Prepare** also runs `php artisan wayfinder:generate --with-form`, so the generated route modules and their `.form()` variants exist before the JavaScript checks.
- **JS static checks** install the packages and run the read-only commands of [tools.md](tools.md#read-only-verification): ESLint, Prettier and TypeScript.
- **JS tests** run Vitest and the production build (`pnpm build`).

## Migrations and seeders

<!-- from: framework/laravel -->
- Migrations run once per deployment in the start entrypoint (`docker/production/start`) of the container with `RUN_MIGRATIONS=true` (`php artisan migrate --force --no-interaction`); worker containers never migrate. They are forward-only and safe to run while the previous release still serves requests ([architecture.md](architecture.md#persistence)). **A failing migration does not stop the container**: the entrypoint writes `MIGRATION FAILED` with the exit code to standard error and starts Supervisor anyway ([The production start entrypoint](#the-production-start-entrypoint)); the person monitoring the deployment reads the log and reacts.
- A production deployment that contains a migration is preceded by an on-demand database backup and, where the project has a staging stack, has already run there; whoever deploys watches the migration output in the log ([Monitoring a deployment](#monitoring-a-deployment)).
- **The start entrypoint never seeds**, and none of the heavy data tasks (recalculations, syncs, imports) runs in front of php-fpm: the entrypoint stays quick, caching configuration and routes and linking storage aside, and such a task is a Supervisor program or a background process.
- **The production seeder is its own class** (`ProductionSeeder`), run by hand in the container (`docker exec <container> php artisan db:seed --class=ProductionSeeder --force`). It creates only what every environment needs. Every seeder it calls is idempotent and create-only: it never overwrites rows staff may have changed, and none creates an account ([security-and-data.md](security-and-data.md#sessions-and-accounts)).
- **A demo seeder** (for example `DemoSeeder`) is another deliberate seeder: deliberate demo content, run by hand the same way (`--class=DemoSeeder`) and allowed in staging and production. It SHOULD NOT use Faker or factories that call it: the production image installs Composer packages with `--no-dev`, and `fakerphp/faker` is a development dependency. It creates no account with credentials outside local development ([security-and-data.md](security-and-data.md#sessions-and-accounts)). A project MAY define further deliberate seeders for its own environments.
- **`DatabaseSeeder` is the general seeder, for local development.** It seeds synthetic data (Faker, factories, a development account) and MAY call the production and demo seeders so that local data has their rows too. It refuses to run outside `local` and `testing`: its `run()` starts with that check and throws, so `php artisan db:seed` without `--class` fails in staging and production instead of writing synthetic data. Any other general seeder that uses Faker or synthetic data without being a deliberate demo seeder gets the same guard.
- Data corrections ship as migrations or as console commands that dispatch commands, never as manual SQL ([security-and-data.md](security-and-data.md#production-access)).

## File storage

<!-- from: framework/laravel -->
- Private files (uploads, documents) live on a private disk and reach a browser only through a **short-lived signed URL** or through a controller that authorizes against the owning record and streams the file. A public URL to a private file does not exist.
- **Code never names a disk driver.** Command handlers reach the disk through a contract in `App\Application\Contract`, implemented in `App\Infrastructure`; the driver is configuration (`config/filesystems.php`).
- Every response that serves a private file sends `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`.
- The type and size limits of uploads are configuration, validated in the form request ([requests-and-validation.md](requests-and-validation.md#validation-rules)); the proxy and PHP accept at least the largest upload ([Reverse proxy and TLS](#reverse-proxy-and-tls)).

> Local additions go below this managed block. Content outside the block is preserved when the guidelines are synced.
<!-- esoul-guidelines:end deployment -->
