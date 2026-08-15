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

# The other misconfiguration worth catching before it turns into a puzzle. The image owns
# every writable path as its build-time uid, and the gid is free - so `user: "1000:100"`
# needs nothing done to it. Moving the UID is the case that breaks, because a storage
# volume that already exists still belongs to whoever made it: the image's ownership is
# copied into a named volume once, at first mount, and a bind mount never inherits
# anything at all.
#
# Unguarded, that surfaces as `view:cache` throwing on a compiled view it cannot write.
# set -e ends the script, the container restart-loops before frankenphp ever listens, and
# horizon and scheduler sit blocked on `depends_on: service_healthy` - so a wrong uid
# takes the whole stack down and blames Blade. Checking here names the actual problem.
#
# mkdir first so a fresh bind mount is created rather than reported: if /app/storage is
# writable the directories appear and the test passes, and if it is not, mkdir fails
# quietly and -w reports it below. Only this container checks, which is enough - the
# other two wait on it being healthy and never get to run against a volume it rejected.
for dir in \
    /app/bootstrap/cache \
    /app/storage/logs \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/app/video-covers
do
    mkdir -p "${dir}" 2>/dev/null || true

    if [ ! -w "${dir}" ]; then
        echo "Cannot write to ${dir}, running as $(id -u):$(id -g)." >&2
        echo "It is owned by $(stat -c '%u:%g' "${dir}" 2>/dev/null || echo 'nobody - it does not exist')." >&2
        echo >&2
        echo 'The uid has to own the storage volume. The gid does not matter, so PGID is' >&2
        echo 'free to be anything; PUID is not, unless the volume agrees. To re-own one:' >&2
        echo >&2
        echo '  docker compose down' >&2
        echo "  docker run --rm -v <stack>_storage:/s alpine chown -R $(id -u):$(id -g) /s" >&2
        echo '  docker compose up -d' >&2
        echo >&2
        echo 'A bind mount is chowned on the host instead. The README explains both.' >&2
        exit 1
    fi
done

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
