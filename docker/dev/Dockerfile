# Development image: php-fpm + Node/pnpm + Supervisor (php-fpm, queue listener, scheduler, Vite).
# The application is bind-mounted at /var/www by docker-compose.yml.
ARG NODE_BASE_VERSION=26-alpine
# Keep in sync with "packageManager" in package.json
ARG PNPM_VERSION=12.21.0

FROM node:${NODE_BASE_VERSION} AS node_base
ARG PNPM_VERSION
# Node 25+ no longer bundles corepack, pnpm comes from npm
RUN npm install --global pnpm@${PNPM_VERSION}

FROM rg.fr-par.scw.cloud/esoul-starters/php-fpm-laravel:8.5

# Host user ids: files written to the bind mount belong to the host user (docker-compose.yml passes them from .env)
ARG WWWUSER=1000
ARG WWWGROUP=1000

RUN apk add --no-cache supervisor libstdc++ libgcc

# Add Composer
RUN install-php-extensions @composer

# Node and pnpm from the official Node image: the distribution would pick the Node major, this pins it
COPY --from=node_base /usr/local /usr/local

RUN getent group "${WWWGROUP}" >/dev/null || addgroup -g "${WWWGROUP}" sail; \
    adduser -D -u "${WWWUSER}" -G "$(getent group "${WWWGROUP}" | cut -d: -f1)" sail

COPY docker/dev/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/dev/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/dev/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/dev/supervisor-php.conf /etc/supervisor/conf.d/php.conf
COPY docker/dev/supervisor-queue-worker.conf /etc/supervisor/conf.d/queue-worker.conf
COPY docker/dev/supervisor-scheduler.conf /etc/supervisor/conf.d/scheduler.conf
COPY docker/dev/supervisor-vite.conf /etc/supervisor/conf.d/vite.conf
COPY docker/dev/start /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www

# Supervisor runs as root and starts the programs as the `sail` user
EXPOSE 9000 5173

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
