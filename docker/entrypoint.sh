#!/bin/sh
set -e

# The environment only exists at run time, so the caches are built here rather
# than during the image build.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
