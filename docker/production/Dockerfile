# Production image: php-fpm on port 9000 under Supervisor, which also runs the queue worker and the scheduler. The image
# contains no web server: nginx runs on the host and reaches php-fpm over FastCGI. The last stage (`app`) is the release image, which is what the pipeline builds.
#
#   base            php-fpm-laravel base image + Supervisor + Composer
#   php_dependencies  production Composer packages (cached while composer.json/lock do not change)
#   php_builder     application code, autoloader, Wayfinder/Filament generated files, Laravel caches
#   node_build      Vite build; needs the generated Wayfinder files from php_builder, not PHP itself
#   app             runtime image
FROM rg.fr-par.scw.cloud/esoul-starters/php-fpm-laravel:8.5 AS base

LABEL org.opencontainers.image.vendor="eSoul"

# `fcgi` provides cgi-fcgi, which the healthcheck uses to request /up from php-fpm
RUN apk add --no-cache supervisor fcgi

# Add Composer
RUN install-php-extensions @composer


FROM base AS php_dependencies

WORKDIR /var/www

# `composer.lock*`: the lock file is optional so that a fresh template still builds
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist


FROM php_dependencies AS php_builder

# Everything the .dockerignore does not exclude
COPY . /var/www

# Build-time environment only, so that artisan can run during the build. The runtime configuration comes
# from the container environment and the entrypoint caches it again.
RUN cp .env.example .env \
    && mkdir -p storage/framework/views storage/framework/cache storage/framework/testing storage/framework/sessions bootstrap/cache \
    && chown -R www-data:www-data /var/www

# dump-autoload runs the post-autoload-dump scripts, i.e. package:discover.
# Generated code of the packages that are installed (Wayfinder, Filament); other variants skip them.
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && for command in "wayfinder:generate --with-form" filament:optimize; do \
        name="${command%% *}"; \
        if php artisan list --raw | grep -q "^${name} "; then php artisan ${command}; fi; \
    done \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan storage:link


FROM node:26-alpine AS node_build

# Keep in sync with "packageManager" in package.json
ARG PNPM_VERSION=12.21.0
ENV PNPM_HOME="/pnpm"
ENV PATH="$PNPM_HOME:$PATH"
# Node 25+ no longer bundles corepack, pnpm comes from npm
RUN npm install --global pnpm@${PNPM_VERSION}

WORKDIR /var/www

COPY package.json pnpm-lock.yaml* pnpm-workspace.yaml .npmrc ./
RUN --mount=type=cache,id=pnpm,target=/pnpm/store pnpm install --frozen-lockfile

# Application code with the generated Wayfinder files. The Vite plugin would call PHP to generate them
# again, this image has none, so tell it they exist.
COPY --from=php_builder /var/www /var/www
ENV WAYFINDER_TYPES_PREGENERATED=true
RUN pnpm run build


FROM base AS app

LABEL org.opencontainers.image.vendor="eSoul"

# Cleanup unnecessary files from base image
RUN rm -rf \
    /var/cache/apk/* \
    /tmp/* \
    /usr/local/lib/php/docs/* \
    /usr/local/lib/php/test/* \
    /usr/local/bin/install-php-extensions

# Copy application files from the builders
COPY --from=php_builder --chown=www-data:www-data /var/www /var/www
COPY --from=node_build --chown=www-data:www-data /var/www/public/build /var/www/public/build

# PHP and php-fpm configuration
COPY docker/production/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Ensure proper permissions for storage and bootstrap/cache
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

WORKDIR /var/www

# RUN_* decide what the entrypoint and Supervisor start; each is exactly `true` or `false`
ENV RUN_MIGRATIONS=true
ENV RUN_SCHEDULER=true
ENV RUN_QUEUE_WORKER=true

# Supervisor configuration and the healthcheck (the Supervisor programs, then a FastCGI request for /up)
COPY docker/production/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/production/supervisor-php.conf /etc/supervisor/conf.d/php.conf
COPY docker/production/supervisor-queue-worker.conf /etc/supervisor/conf.d/queue-worker.conf
COPY docker/production/supervisor-scheduler.conf /etc/supervisor/conf.d/scheduler.conf
RUN sed -i 's/^autostart=true$/autostart=%(ENV_RUN_QUEUE_WORKER)s/' /etc/supervisor/conf.d/queue-worker.conf \
    && sed -i 's/^autostart=true$/autostart=%(ENV_RUN_SCHEDULER)s/' /etc/supervisor/conf.d/scheduler.conf
COPY docker/production/healthcheck /usr/local/bin/healthcheck
COPY docker/production/start /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/healthcheck /usr/local/bin/entrypoint.sh
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 CMD ["/usr/local/bin/healthcheck"]

# Supervisor runs as root and starts the worker and the scheduler as www-data

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
