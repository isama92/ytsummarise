#!/bin/sh
# Runs on every container start, before the web server takes over.
#
# The caches are built here rather than baked into the image because every one of
# them is compiled from the runtime environment - config:cache in particular freezes
# whatever env() returns, so an image built with one set of values could never be
# deployed with another.
set -e

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# --force because artisan refuses to touch a production database interactively.
# This assumes a single replica: two containers starting together would race here.
# If the database is not reachable yet the container exits and `restart:
# unless-stopped` retries, which is the intended behaviour - a web server answering
# 500s is worse than one that is visibly down.
php artisan migrate --force

# Hand back to the base image's entrypoint rather than exec'ing frankenphp directly,
# so the default CMD (`--config ... --adapter caddyfile`) keeps working: that script
# is what turns a leading `-` argument into `frankenphp run "$@"`.
exec docker-php-entrypoint "$@"
