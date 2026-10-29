FROM alfarid-app:local
USER root
COPY --chown=www-data:www-data backend/tests/ /var/www/html/tests/
COPY --chown=www-data:www-data frontend/apps/storefront/public/images/demo/ /var/www/frontend/apps/storefront/public/images/demo/
RUN APP_ENV=local composer install --no-interaction --no-progress \
    && touch /var/www/html/.env \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
USER www-data
