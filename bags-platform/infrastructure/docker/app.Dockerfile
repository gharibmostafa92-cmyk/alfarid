# Laravel behind Nginx + PHP-FPM (serversideup image), 3 identical app nodes.
# Build from the repo root (bags-platform/):
#   docker build -f infrastructure/docker/app.Dockerfile -t alfarid-app:local .
FROM serversideup/php:8.3-fpm-nginx AS base

USER root

# bcmath is required by composer.json; intl/gd/exif/pdo_pgsql mirror production needs.
RUN install-php-extensions bcmath intl gd exif pdo_pgsql redis zip

WORKDIR /var/www/html/
COPY backend/composer.json backend/composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-interaction --no-progress
COPY --chown=www-data:www-data backend/ /var/www/html/
RUN APP_ENV=local composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing storage/app/private storage/app/public \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY infrastructure/docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY infrastructure/docker/entrypoint.d/ /etc/entrypoint.d/
RUN chmod +x /etc/entrypoint.d/50-laravel.sh

# The image defaults to port 8080; HAProxy talks to appN:8080 on the appnet network.
EXPOSE 8080

USER www-data

# IMPORTANT: set AUTORUN_ENABLED=false at runtime so migrations never run
# automatically on every node. Migrate once from a one-off container
# (see infrastructure/scripts/rolling-deploy.sh).
