#!/bin/sh
set -e

# The environment only exists at run time.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
