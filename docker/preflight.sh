#!/bin/sh
# Proves the container can write where it has to, before anything tries to.
#
# Run by all three services and not just the web one. The obvious place for this is
# app-entrypoint.sh, which horizon and scheduler deliberately bypass - and relying on
# their `depends_on: ytsummarise: service_healthy` to cover them is wrong in exactly one
# case that matters: `docker compose up` honours that ordering, but a host reboot does
# not. The daemon restarts all three from `restart: unless-stopped` independently, so
# horizon comes up beside a web container that is still restart-looping, needs nothing
# but Redis to start working, and drains the queue against a storage tree it cannot write
# to. Every cover write then fails silently, because the video-covers disk is configured
# `throw => false, report => false`. A backlog gets marked done with no covers and no log
# line, which is the worst outcome available here and the reason this is its own file.
#
# What can actually be wrong is one thing: the storage volume does not belong to the uid
# this is running as. Everything written at runtime lives under /app/storage - the
# framework's scratch directories, the cover images, the three bootstrap caches and
# Caddy's own state, the last two moved here by the ENV block in the Dockerfile
# precisely so that this is true. So there is one question to ask and one answer to give.
set -e

for dir in \
    /app/storage/app/private \
    /app/storage/app/public \
    /app/storage/app/video-covers \
    /app/storage/caddy/config \
    /app/storage/caddy/data \
    /app/storage/framework/bootstrap \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs
do
    # A bind mount arrives empty, so create before testing: if /app/storage is writable
    # the directory appears and -w passes. stderr is deliberately NOT swallowed here.
    # mkdir is the only thing that knows the difference between "owned by somebody else",
    # a read-only mount and a full disk, and a guard that reports all three as an
    # ownership problem sends the operator to chown a volume that was never the fault.
    mkdir -p "${dir}" 2>/dev/null || mkdir -p "${dir}" || true

    if [ -w "${dir}" ]; then
        continue
    fi

    owner=$(stat -c '%u:%g' "${dir}" 2>/dev/null) || owner='nobody, it does not exist'

    echo "Cannot write to ${dir}." >&2
    echo "Running as $(id -u):$(id -g); that path belongs to ${owner}." >&2
    echo >&2
    echo 'The storage volume has to be owned by the uid this runs as. The gid does not' >&2
    echo 'matter, so PGID is free; PUID is too, as long as the volume agrees with it.' >&2
    echo >&2
    echo '  docker compose down' >&2
    echo '  docker volume ls                    # <stack> is the directory compose ran in' >&2
    echo "  docker run --rm -v <stack>_storage:/s alpine chown -R $(id -u):$(id -g) /s" >&2
    echo '  docker compose up -d' >&2
    echo >&2
    echo "A bind mount is chowned on the host instead: chown -R $(id -u):$(id -g) ./storage" >&2
    echo 'The README section "Which user it runs as" has the whole of it.' >&2

    exit 1
done
