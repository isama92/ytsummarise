#!/bin/sh
# Runs on every container start, before the web server takes over.
#
# The caches are built here rather than baked into the image because every one of
# them is compiled from the runtime environment - config:cache in particular freezes
# whatever env() returns, so an image built with one set of values could never be
# deployed with another.
set -e

# An empty APP_KEY is the one misconfiguration this cannot survive and would not report.
# Nothing below touches the encrypter, and /up is registered without the web middleware
# group, so an empty key still caches, still migrates and still answers the healthcheck
# 200 - and then 500s on every real request, with the queue and the scheduler released
# into working against it by that healthy status. Failing here stops the deploy instead,
# and because those two wait on this container being healthy, it stops all three.
if [ -z "${APP_KEY}" ]; then
    echo 'APP_KEY is empty. Laravel cannot encrypt cookies or sessions without it.' >&2
    echo 'Generate one with: echo "base64:$(openssl rand -base64 32)"' >&2
    echo 'then set it in .env and deploy again. The README explains why this is fatal.' >&2
    exit 1
fi

# The other misconfiguration this cannot survive, and the one that would otherwise
# surface as `view:cache` throwing on a compiled view: a storage tree the container
# cannot write to. Its own file, because horizon and scheduler run it too - see the
# script for why their depends_on is not the cover it looks like.
app-preflight.sh

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
