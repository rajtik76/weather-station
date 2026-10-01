#!/bin/sh
set -e

# Env exists only at run time.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
