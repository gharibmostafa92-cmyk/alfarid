#!/bin/sh
# Caching happens at container start (not at build time) because env values
# differ per environment.
set -e
php artisan config:cache
php artisan route:cache
php artisan event:cache
