# syntax=docker/dockerfile:1
#
# Production image. Build with `--target prod`; the earlier stages exist only to
# feed it and never ship.
#
# The build runs on Debian and the runtime on Alpine, which is the one thing here
# worth understanding before changing anything:
#
#   - The BUILD runs on Debian, i.e. glibc. rolldown (which vite 8 uses in place of
#     rollup), @tailwindcss/oxide and lightningcss are all native, and npm selects
#     each one's platform binary from that package's own os/cpu-gated
#     optionalDependencies. .npmrc sets ignore-scripts=true, which is fine because
#     none of them need a postinstall step to do it. package.json used to pin the
#     glibc binaries by hand; those pins went away with the move to vite 8, which
#     left the rollup ones pointing at a package no longer in the tree at all.
#
#   - The RUNTIME does not. Nothing compiled crosses the boundary: PHP extensions are
#     installed per-stage, node never enters `prod`, and composer's output is pure PHP.
#     Alpine's FrankenPHP is 302MB against bookworm's 872MB, so staying on Debian for
#     the runtime would cost about 570MB on every pull for nothing.
#
#   - The invariant that keeps this honest: everything copied out of a build stage must
#     be libc-agnostic. That holds for vendor/ and public/build today. A composer
#     package shipping a compiled component would break it, and the symptom would be a
#     runtime "cannot open shared object file", not a build failure.
#
# The other non-obvious constraint is that the asset build needs PHP, not just node:
# @laravel/vite-plugin-wayfinder shells out to `php artisan wayfinder:generate` from
# its buildStart() hook, and resources/js/{actions,routes,wayfinder} are gitignored -
# they exist only after that command runs. Hence node grafted onto a PHP base rather
# than the reverse.
#
# Finally, the build needs outbound network to fonts.bunny.net: vite.config.ts passes
# `fonts: [bunny('Instrument Sans')]` to laravel-vite-plugin, which downloads the faces
# at build time and self-hosts them under public/build/assets. An offline `docker build`
# fails there, with a fetch error rather than anything self-explanatory.

FROM dunglas/frankenphp:php8.5-bookworm AS build-base
WORKDIR /app

# Both of these are BUILD requirements rather than runtime ones, which is the whole reason
# they are here and not only in `prod`: `composer install` below verifies platform
# requirements and stops with "requires ext-x * -> it is missing from your system" before a
# single package is written.
#
# pcntl because Horizon lists ext-pcntl and ext-posix as hard requirements and the base image
# has only posix. redis because composer.json requires ext-redis - declared deliberately, so
# that dropping it from the `prod` line below fails this build instead of failing at the
# first session read on a running container.
#
# This is the stage both `vendor` and `assets` inherit; `prod` is a different image and
# shares nothing with it, so it installs its own.
RUN install-php-extensions pcntl redis


# PHP dependencies. Split so the expensive `composer install` is keyed on the lock file
# alone and survives ordinary source edits.
FROM build-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
# The base image has neither: composer needs unzip for --prefer-dist archives and git
# for anything resolved from source. Build-only, so neither reaches `prod`.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
# --no-scripts and --no-autoloader because both need the source tree, which is not here
# yet; they run below once it is.
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi


# Frontend build. Inherits build-base (not vendor) so node lands on a clean layer, then
# takes the application in from vendor afterwards.
FROM build-base AS assets

COPY --from=node:24-bookworm-slim /usr/local/bin/ /usr/local/bin/
COPY --from=node:24-bookworm-slim /usr/local/lib/node_modules/ /usr/local/lib/node_modules/

# Ahead of the source copy so `npm ci` is keyed on the lock file only. .npmrc comes
# along because it sets ignore-scripts=true, so nothing here may depend on a
# package's postinstall step running.
COPY package.json package-lock.json .npmrc ./
RUN --mount=type=cache,target=/root/.npm npm ci

# Merges on top of node_modules rather than replacing it - COPY does not clear the
# destination directory.
COPY --from=vendor /app /app

# artisan has to boot for wayfinder:generate to enumerate the routes, and booting wants
# an APP_KEY. This .env is a throwaway that never leaves this stage: `prod` copies only
# public/build out of here.
#
# One value does escape, and it surprises people: VITE_APP_NAME is inlined into the
# JavaScript bundle here and frozen for the life of the image. app.tsx uses it for the
# document title, so setting APP_NAME in the production .env changes the title Blade
# renders but NOT the one the client sets after hydration. Change it in .env.example
# and rebuild.
#
# The three driver overrides are what keep this stage buildable. .env.example puts the cache,
# the session and the queue on Redis, and there is no Redis in a build - so anything booting
# artisan here is one stray cache read away from a connection refused. Nothing in
# key:generate or wayfinder:generate touches a store today; these say so rather than leaving
# it to luck, and they never leave the stage either.
RUN cp .env.example .env \
    && printf '\nCACHE_STORE=array\nSESSION_DRIVER=array\nQUEUE_CONNECTION=sync\n' >> .env \
    && php artisan key:generate --no-interaction \
    && npm run build


FROM dunglas/frankenphp:php8.5-alpine AS prod

# install-php-extensions (shipped in the base image) rather than docker-php-ext-install,
# because it pulls the postgres headers for the build and leaves only the runtime
# library behind.
#
# pcntl and posix are Horizon's, and posix is the only one of the three already present:
# without pcntl the master supervisor cannot fork, and without redis there is no queue for
# it to supervise. phpredis rather than predis/predis deliberately - it is what Horizon
# recommends, and installing it per-stage keeps the libc-agnostic invariant above intact,
# which a compiled composer package would not.
RUN install-php-extensions pdo_pgsql pcntl redis

# Take the file capability off the frankenphp binary, which the base image ships as
# `cap_net_bind_service=ep` so it can bind :80 out of the box. This image binds :8080 and has
# never needed it - but leaving it on does not merely waste a privilege, it stops the binary
# running at all under the `cap_drop: ALL` in compose.yml.
#
# The reason is the `e`, the effective bit: it tells the kernel to grant the capability on
# exec, mandatorily. When the capability is not in the process's bounding set the kernel
# cannot honour that, and it refuses the exec rather than starting the process without it. So
# the symptom is not a failure to bind a port; it is `frankenphp: Operation not permitted`
# from docker-php-entrypoint, on an image whose every other command works, and a web container
# that restart-loops having already migrated the database.
#
# Stripping it here rather than adding the capability back in compose keeps the reasoning at
# ENV SERVER_NAME below true - this container really does need no capabilities - and fixes
# every deployment of this image at once rather than each operator's compose file.
#
# libcap is build-only and removed in the same layer. The copy-up of a 57MB binary to change
# one extended attribute costs about 20MB in the image, which is the price of the whole
# cap_drop: ALL posture being real rather than documented.
RUN apk add --no-cache libcap \
    && setcap -r /usr/local/bin/frankenphp \
    && apk del libcap

# yt-dlp, without which this image can accept a video and never summarise one: it is the only
# thing here that can find a caption track, and no captions means no summary. FetchTranscript
# looks it up on PATH by default (YT_DLP_BINARY), and the horizon container is where it runs -
# but all three services come off this one image, so it lands in all of them.
#
# Metadata only, so no ffmpeg: the command is --dump-single-json --skip-download, and the
# caption track it names is then fetched over plain http by the application itself.
#
# The cost is about 117MB, nearly all of it the python this pulls in - real against an Alpine
# runtime chosen to save 570MB, and still comfortably ahead. The standalone musllinux build is
# a third of the size, but it wants a pinned version and a checksum, and a pin is the thing
# most likely to go stale here. That matters more than the megabytes: yt-dlp breaks whenever
# YouTube changes its player, so the version wants to move on every rebuild, which is exactly
# what an unpinned apk package does and a vendored binary does not.
#
# Run once at build so a package that installed but cannot start - a broken python, a bad
# release - fails here rather than as an "unavailable" transcript weeks later.
RUN apk add --no-cache yt-dlp \
    && yt-dlp --version

# The image ships php.ini-development and php.ini-production but activates neither, so
# PHP would run on built-in defaults - display_errors among them. Put the production one
# in place before layering opcache settings over it.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/opcache.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"

WORKDIR /app

# Who this runs as. The base image ships www-data at 82:82, which is an Alpine packaging
# convention and matches nothing on a Linux host - so a bind mount at /app/storage is
# owned by somebody else's uid and the container cannot write a cover image into it.
# 1000:1000 is the first ordinary user on a Debian, Ubuntu or Arch install, which makes
# the common case work with no chown by hand. That is the whole reason for moving off
# www-data; nothing here wants a *particular* user, only one the host agrees with.
#
# Build args rather than fixed values so an operator building their own image can bake in
# whatever their host uses:
#
#   docker build --target prod --build-arg UID=1500 --build-arg GID=1500 -t ytsummarise .
#
# Anyone running the PUBLISHED image changes it at run time instead, with compose's
# `user:` key - see compose.yml, which reads PUID and PGID for exactly that and explains
# why it is not the linuxserver.io root-then-drop entrypoint.
#
# Declared here, below every expensive layer above, so changing either one does not
# reinstall the extensions or yt-dlp.
ARG UID=1000
ARG GID=1000

# -D for no password and no ageing; the login shell is nologin because nothing ever signs
# in as this account. The home directory is not decoration: HOME is where yt-dlp keeps its
# player cache, and without a writable one every metadata lookup in the horizon container
# starts by failing to write it.
#
# A UID or GID already taken in the base image (82 is www-data's) fails the build here
# rather than quietly producing an image with two names for one id.
RUN addgroup -g "${GID}" app \
    && adduser -u "${UID}" -G app -h /home/app -s /sbin/nologin -D app

COPY --from=vendor --chown=${UID}:${GID} /app /app
COPY --from=assets --chown=${UID}:${GID} /app/public/build /app/public/build
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/app-entrypoint.sh

# /data and /config are Caddy's storage (XDG_DATA_HOME and XDG_CONFIG_HOME in the base
# image) and are root-owned; without this the unprivileged process cannot start. The
# storage tree is recreated because .dockerignore strips its contents, and it is chowned
# so the named volume mounted over it inherits writable ownership on first use.
#
# g+rwX on top of the chown is what makes an override survive a NAMED volume. Docker
# copies the image's ownership into an empty named volume once, at first mount, and never
# again - so `user: "1500:1000"` against a volume created at 1000:1000 can only write if
# the group can. X rather than x so the bit lands on directories and on files that are
# already executable, and not on every .php file in the tree.
RUN mkdir -p \
        /app/storage/framework/cache \
        /app/storage/framework/sessions \
        /app/storage/framework/views \
        /app/storage/logs \
        /app/bootstrap/cache \
    && chown -R "${UID}:${GID}" /app/storage /app/bootstrap/cache /data /config \
    && chmod -R g+rwX /app/storage /app/bootstrap/cache /data /config /home/app

# 8080, not 80: a port above 1024 needs no capability, which is what lets compose run
# this container with `cap_drop: ALL` and nothing added back. Traefik's
# loadbalancer.server.port in compose.yml has to agree with this.
ENV SERVER_NAME=":8080" \
    SERVER_ROOT="/app/public"

# Numeric rather than `USER app`, so the image declares an id a host can reason about
# instead of a name that only means something inside it. Both forms are the same process
# either way; this one matches what `docker inspect` and compose's `user:` speak.
USER ${UID}:${GID}
EXPOSE 8080

ENTRYPOINT ["app-entrypoint.sh"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
