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

# Horizon lists ext-pcntl and ext-posix as hard requirements. The base image has posix and
# not pcntl, which makes this a BUILD requirement rather than a runtime one: `composer
# install` below verifies the lock file's platform requirements and stops with "requires
# ext-pcntl * -> it is missing from your system" before a single package is written. So it
# belongs here, in the stage both `vendor` and `assets` inherit, and separately in `prod`,
# which is a different image and shares nothing with this one.
RUN install-php-extensions pcntl


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

# The image ships php.ini-development and php.ini-production but activates neither, so
# PHP would run on built-in defaults - display_errors among them. Put the production one
# in place before layering opcache settings over it.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/opcache.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"

WORKDIR /app

COPY --from=vendor --chown=www-data:www-data /app /app
COPY --from=assets --chown=www-data:www-data /app/public/build /app/public/build
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/app-entrypoint.sh

# /data and /config are Caddy's storage (XDG_DATA_HOME and XDG_CONFIG_HOME in the base
# image) and are root-owned; without this the unprivileged process cannot start. The
# storage tree is recreated because .dockerignore strips its contents, and it is chowned
# so the named volume mounted over it inherits writable ownership on first use.
RUN mkdir -p \
        /app/storage/framework/cache \
        /app/storage/framework/sessions \
        /app/storage/framework/views \
        /app/storage/logs \
        /app/bootstrap/cache \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data /config

# 8080, not 80: a port above 1024 needs no capability, which is what lets compose run
# this container with `cap_drop: ALL` and nothing added back. Traefik's
# loadbalancer.server.port in compose.yml has to agree with this.
ENV SERVER_NAME=":8080" \
    SERVER_ROOT="/app/public"

USER www-data
EXPOSE 8080

ENTRYPOINT ["app-entrypoint.sh"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
